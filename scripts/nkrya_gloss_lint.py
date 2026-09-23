#!/usr/bin/env python3
"""NKRYa gloss lint for Russian beginner SRS decks (H5285).

Per Russian gloss in an SRS source TSV:

  1. tokenize; lemmatize every Cyrillic token with pymorphy3;
  2. flag `inflected` when a one-word gloss is not in dictionary form
     («сделал» -> «сделать», «сближении» -> «сближение») and propose the lemma
     (grill 23-09-2026 Q2: mechanical, reversible, no vote);
  3. look up the NKRYa (ruscorpora.ru) frequency band of each content lemma
     (band 1 = under 1 ipm ... 6 = most frequent) — band 1 / zero ipm is `rare`,
     band 2 is `soft_rare` (Q4: band 2 is a soft note only);
  4. for `rare` lemmas, count hits in a modern (1950+) and a 19th-century slice;
     modern 0 + 19c > 0 is `c19_only`;
  5. for `rare` rows, propose the highest-ipm synonym already present among the
     source's glosses for the same Sanskrit lemma (sa_ru_glossary.json) — a
     synonym swap goes to a human vote, never applied here.

The source TSVs are never edited: they are generated fixtures (roots from
build_roots_frequency_ru.py, lemmas from the pinned start-chteniya feed). Output is a
lint TSV per source + a compact evidence cache that makes `--offline` re-runs exact.

NKRYa client: the H5261 client (SanskritLexicography/RussianTranslation/src/nkrya_client.py,
sibling checkout or env NKRYA_CLIENT_SRC) until H5282 moves it into csl-pyutil.
Rate limit: 5 successful calls per minute per ACCOUNT, shared by every session using
the key (cache timestamps 13:40-13:56Z 23-09-2026: 4-5/min across all sessions, body
`{"detail": "Too many requests."}`, no Retry-After) -> `--interval` 12 s + a `--backoff`
30 s wait on 429; the evidence cache is saved every 20 lookups, so a killed run resumes.

  python scripts/nkrya_gloss_lint.py roots lemmas            # live, resumable
  python scripts/nkrya_gloss_lint.py roots lemmas --offline  # cache only
  python scripts/nkrya_gloss_lint.py --tsv database/seeders/data/memrise_6502608/level_*.csv \
      --gloss-col col_b --id-cols col_a --name memrise_6502608 --out-dir /tmp/lint
  python scripts/nkrya_gloss_lint.py --selftest
"""

import argparse
import csv
import json
import os
import re
import sys
import time
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

REPO = Path(__file__).resolve().parent.parent
OUT_DIR = REPO / "resources" / "data" / "nkrya_lint"
EVIDENCE_TSV = OUT_DIR / "nkrya_evidence_cache.tsv"
RAW_CACHE = REPO / "storage" / "app" / "nkrya_cache"      # gitignored raw API responses
GLOSSARY = REPO / "resources" / "data" / "sa_ru_glossary.json"

PRESETS = {
    "roots": {"path": "database/seeders/data/roots_frequency_ru.tsv", "gloss": "gloss_ru",
              "ids": ["rank", "root_iast", "dcs_lemma"], "sa_key": "dcs_lemma", "sa_kind": "iast"},
    "lemmas": {"path": "resources/data/cohort_start_chteniya/lemmas_for_srs.tsv",
               "gloss": "gloss_ru", "ids": ["pack", "lemma_slp1", "surface", "locus"],
               "sa_key": "lemma_slp1", "sa_kind": "slp1"},
}

# pymorphy3 POS -> NKRYa word-portrait POS
POS_MAP = {"NOUN": "S", "ADJF": "A", "ADJS": "A", "COMP": "A", "VERB": "V", "INFN": "V",
           "PRTF": "V", "PRTS": "V", "GRND": "V", "ADVB": "ADV", "NUMR": "NUM",
           "NPRO": "SPRO", "PRED": "PRAEDIC"}
CONTENT = {"NOUN", "ADJF", "ADJS", "COMP", "VERB", "INFN", "PRTF", "PRTS", "GRND", "ADVB"}
CYR = re.compile(r"[А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)*")
LATIN = re.compile(r"[A-Za-z]")
C19 = {"fieldName": "created", "intRange": {"begin": 1800, "end": 1899}}
MODERN = {"fieldName": "created", "intRange": {"begin": 1950, "end": 2026}}


def yo(s):
    return s.replace("ё", "е").replace("Ё", "Е")


# ---- lemmatization -------------------------------------------------------------------
class Lemmatizer:
    def __init__(self, morph=None):
        if morph is None:
            import pymorphy3
            morph = pymorphy3.MorphAnalyzer()
        self.morph = morph

    def analyse(self, token):
        """(lemma, pymorphy_pos, dictionary_form) for one token.

        dictionary_form is what a one-word card should show: infinitive for finite
        verbs and gerunds, nominative for nouns, masc nom sg full form for adjectives,
        masc nom sg for participles (a participle gloss of a kta form stays a
        participle); comparatives and adverbs are left alone.
        """
        p = self.morph.parse(token.lower())[0]
        pos = p.tag.POS or ""
        lemma = p.normal_form
        form = token.lower()
        if pos in ("ADJF", "PRTF") and "nomn" in p.tag:
            pass                   # «внутреннее» may be a substantivized neuter: keep gender
        elif pos in ("VERB", "GRND", "INFN", "NOUN", "ADJF", "ADJS"):
            form = lemma
        elif pos in ("PRTF", "PRTS"):
            inf = p.inflect({"masc", "sing", "nomn"}) if pos == "PRTF" else None
            if pos == "PRTS":
                full = self.morph.parse(token.lower())
                full = next((q for q in full if q.tag.POS == "PRTS"), p)
                inf = full.inflect({"PRTF", "masc", "sing", "nomn"})
            form = inf.word if inf else form
            lemma = form           # NKRYa lemmatizes participles to the verb; see freq_key
        return lemma, pos, form


# ---- evidence (compact committed cache over the raw client cache) ---------------------
class Evidence:
    FIELDS = ["key", "ipm", "category", "hits"]

    def __init__(self, path=EVIDENCE_TSV, client=None, offline=False, save_every=20,
                 backoff=30.0):
        self.path = Path(path)
        self.client = client
        self.offline = offline
        self.save_every = save_every
        self.backoff = backoff
        self.rows = {}
        self.live = 0
        self.misses = 0
        self.rate_limited = 0
        if self.path.exists():
            with open(self.path, encoding="utf-8", newline="") as f:
                for r in csv.DictReader(f, delimiter="\t"):
                    self.rows[r["key"]] = r

    def save(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with open(self.path, "w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, self.FIELDS, delimiter="\t", lineterminator="\n")
            w.writeheader()
            for k in sorted(self.rows):
                w.writerow({x: self.rows[k].get(x, "") for x in self.FIELDS})

    def _live(self, fn):
        from nkrya_client import NkryaError
        for attempt in range(240):     # a busy shared key can starve us for a long while
            try:
                out = fn()
                self.live += 1
                if self.live % self.save_every == 0:
                    self.save()
                    print("  … %d live lookups, cache saved" % self.live, file=sys.stderr)
                return out
            except NkryaError as e:
                if "429" in str(e) and attempt < 239:
                    self.rate_limited += 1
                    print("  429 — sleeping %.0f s" % self.backoff, file=sys.stderr)
                    time.sleep(self.backoff)
                    continue
                raise

    def freq(self, lemma, npos):
        key = "freq|%s|%s" % (yo(lemma), npos or "")
        if key not in self.rows:
            if self.offline or self.client is None:
                self.misses += 1
                return None
            f = self._live(lambda: self.client.freq(lemma, npos or None))
            if f.get("ipm") is None and npos:
                f = self._live(lambda: self.client.freq(lemma, None))
            self.rows[key] = {"key": key, "ipm": "" if f.get("ipm") is None else f["ipm"],
                              "category": "" if f.get("category") is None else f["category"]}
        r = self.rows[key]
        return {"ipm": float(r["ipm"]) if r["ipm"] not in ("", None) else None,
                "category": int(r["category"]) if r["category"] not in ("", None) else None}

    def hits(self, lemma, slice_name):
        key = "hits|%s|%s" % (yo(lemma), slice_name)
        if key not in self.rows:
            if self.offline or self.client is None:
                self.misses += 1
                return None
            cond = [C19 if slice_name == "c19" else MODERN]
            q = {"sectionValues": [{"subsectionValues": [
                {"conditionValues": [{"fieldName": "lex", "text": {"v": lemma}}]}]}]}
            c = self._live(lambda: self.client.concordance(q, n=1, subcorpus_conditions=cond))
            self.rows[key] = {"key": key, "hits": "" if c.get("hits") is None else c["hits"]}
        v = self.rows[key].get("hits")
        return int(v) if v not in ("", None) else None


def freq_lemma(lemma, pos, lem):
    """NKRYa indexes participles under the verb: look them up by the infinitive."""
    if pos in ("PRTF", "PRTS"):
        return lem.morph.parse(lemma)[0].normal_form
    return lemma


# ---- lint ----------------------------------------------------------------------------
def load_pool(path=GLOSSARY):
    """Sanskrit lemma -> RU glosses already in our data (sa_ru_glossary.json, top 3)."""
    if not Path(path).exists():
        return {}, {}
    with open(path, encoding="utf-8") as f:
        entries = json.load(f)["entries"]
    by_iast = {k: v.get("g") or [] for k, v in entries.items()}
    by_slp1 = {v.get("slp1"): v.get("g") or [] for v in entries.values() if v.get("slp1")}
    return by_iast, by_slp1


def band_flag(f):
    if f is None:
        return "unknown"
    if f["ipm"] is None or f["ipm"] == 0 or f["category"] is None:
        return "rare"
    if f["category"] <= 1:
        return "rare"
    if f["category"] == 2:
        return "soft_rare"
    return ""


def lint_gloss(gloss, lem, ev):
    """Return a dict of lint fields for one gloss string."""
    g = (gloss or "").strip()
    out = {"status": "", "flags": [], "gloss_lemma": "", "tokens": [], "min_cat": None,
           "rare_lemmas": []}
    if not g:
        out["status"] = "empty"
        return out
    if LATIN.search(g) and not CYR.search(g):
        out["status"] = "not_russian"
        out["flags"].append("not_russian")
        return out
    toks = CYR.findall(g)
    analysed = [(t,) + lem.analyse(t) for t in toks]
    content = [a for a in analysed if a[2] in CONTENT]
    one_word = len(toks) == 1 and not re.search(r"[,;/()]", g)
    if one_word:
        t, lemma, pos, form = analysed[0]
        if yo(form) != yo(t.lower()):
            out["flags"].append("inflected")
            out["gloss_lemma"] = form
    else:
        out["flags"].append("phrase")
    for t, lemma, pos, form in content:
        fl = freq_lemma(lemma, pos, lem)
        f = ev.freq(fl, POS_MAP.get(pos))
        flag = band_flag(f)
        cat = None if f is None else f["category"]
        ipm = None if f is None else f["ipm"]
        out["tokens"].append("%s:%s:%s:%s" % (fl, POS_MAP.get(pos, pos), cat if cat is not None else "-",
                                              ("%.2f" % ipm) if ipm is not None else "-"))
        if cat is not None and (out["min_cat"] is None or cat < out["min_cat"]):
            out["min_cat"] = cat
        if flag == "rare":
            out["rare_lemmas"].append((fl, POS_MAP.get(pos)))
            if "rare" not in out["flags"]:
                out["flags"].append("rare")
            modern, c19 = ev.hits(fl, "modern"), ev.hits(fl, "c19")
            if modern == 0 and (c19 or 0) > 0 and "c19_only" not in out["flags"]:
                out["flags"].append("c19_only")
        elif flag == "soft_rare" and "soft_rare" not in out["flags"]:
            out["flags"].append("soft_rare")
        elif flag == "unknown" and "unknown" not in out["flags"]:
            out["flags"].append("unknown")
    out["status"] = "flag" if set(out["flags"]) - {"phrase"} else "ok"
    return out


def propose_synonym(gloss, pool_glosses, lem, ev, min_cat):
    """Highest-ipm one-word gloss from the pool whose band beats the flagged gloss."""
    best = None
    seen = set()
    for pg in pool_glosses:
        toks = CYR.findall(pg or "")
        if len(toks) != 1:
            continue
        _t, lemma, pos, form = (toks[0],) + lem.analyse(toks[0])
        if pos not in CONTENT or yo(form) in seen or yo(form) == yo((gloss or "").lower()):
            continue
        seen.add(yo(form))
        f = ev.freq(freq_lemma(lemma, pos, lem), POS_MAP.get(pos))
        if not f or f["ipm"] is None or f["category"] is None:
            continue
        if min_cat is not None and f["category"] <= min_cat:
            continue
        if best is None or f["ipm"] > best[1]:
            best = (form, f["ipm"], f["category"])
    return best


def lint_file(name, spec, lem, ev, pool, out_dir=OUT_DIR, limit=None):
    by_iast, by_slp1 = pool
    src = REPO / spec["path"]
    with open(src, encoding="utf-8", newline="") as f:
        rows = list(csv.DictReader(f, delimiter="," if src.suffix == ".csv" else "\t"))
    if limit:
        rows = rows[:limit]
    fields = spec["ids"] + ["gloss_ru", "status", "flags", "gloss_lemma", "min_band",
                            "nkrya_tokens", "synonym_proposal", "synonym_ipm", "synonym_band"]
    out_rows = []
    for i, r in enumerate(rows, 1):
        gloss = r.get(spec["gloss"], "")
        res = lint_gloss(gloss, lem, ev)
        syn = None
        if "rare" in res["flags"]:
            key = r.get(spec["sa_key"], "")
            pg = (by_iast if spec["sa_kind"] == "iast" else by_slp1).get(key, [])
            syn = propose_synonym(gloss, pg, lem, ev, res["min_cat"])
        out_rows.append({**{k: r.get(k, "") for k in spec["ids"]}, "gloss_ru": gloss,
                         "status": res["status"], "flags": ",".join(res["flags"]),
                         "gloss_lemma": res["gloss_lemma"],
                         "min_band": "" if res["min_cat"] is None else res["min_cat"],
                         "nkrya_tokens": " ".join(res["tokens"]),
                         "synonym_proposal": syn[0] if syn else "",
                         "synonym_ipm": ("%.2f" % syn[1]) if syn else "",
                         "synonym_band": syn[2] if syn else ""})
        if i % 100 == 0:
            print("  %s: %d/%d rows" % (name, i, len(rows)), file=sys.stderr)
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    out = out_dir / ("%s_gloss_lint.tsv" % name)
    with open(out, "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fields, delimiter="\t", lineterminator="\n")
        w.writeheader()
        w.writerows(out_rows)
    return out, out_rows


def summarize(name, rows):
    from collections import Counter
    c = Counter()
    for r in rows:
        c["rows"] += 1
        c["status:" + r["status"]] += 1
        for fl in filter(None, r["flags"].split(",")):
            c["flag:" + fl] += 1
        if r["synonym_proposal"]:
            c["synonym_proposals"] += 1
    return dict(sorted(c.items()))


# ---- selftest (offline: stub morph, stub evidence) -----------------------------------
def selftest():
    import tempfile

    class Tag:
        def __init__(self, pos):
            self.POS = pos

        def __contains__(self, _gram):
            return False

    class Parse:
        def __init__(self, word, pos, normal, infl=None):
            self.word, self.tag, self.normal_form, self._infl = word, Tag(pos), normal, infl

        def inflect(self, _grams):
            return Parse(self._infl, "PRTF", self.normal_form) if self._infl else None

    table = {"сделал": ("VERB", "сделать"), "сближении": ("NOUN", "сближение"),
             "искусен": ("ADJS", "искусный"), "выше": ("COMP", "высокий"),
             "вызвавший": ("PRTF", "вызвать", "вызвавший"), "появление": ("NOUN", "появление"),
             "туча": ("NOUN", "туча"), "облако": ("NOUN", "облако"), "и": ("CONJ", "и"),
             "ланиты": ("NOUN", "ланита"), "щёки": ("NOUN", "щека"), "щека": ("NOUN", "щека")}

    class Morph:
        def parse(self, w):
            pos, normal, *infl = table.get(w, ("NOUN", w))
            return [Parse(w, pos, normal, infl[0] if infl else None)]

    lem = Lemmatizer(Morph())
    assert lem.analyse("сделал") == ("сделать", "VERB", "сделать")
    assert lem.analyse("искусен")[2] == "искусный"
    assert lem.analyse("выше")[2] == "выше", "comparatives are never normalized"
    assert lem.analyse("вызвавший")[2] == "вызвавший", "participle stays a participle"

    tmp = Path(tempfile.mkdtemp())
    ev = Evidence(tmp / "ev.tsv", offline=True)
    ev.rows = {"freq|сделать|V": {"key": "freq|сделать|V", "ipm": "900", "category": "5"},
               "freq|сближение|S": {"key": "freq|сближение|S", "ipm": "8", "category": "2"},
               "freq|ланита|S": {"key": "freq|ланита|S", "ipm": "0.4", "category": "1"},
               "freq|щека|S": {"key": "freq|щека|S", "ipm": "60", "category": "3"},
               "hits|ланита|modern": {"key": "hits|ланита|modern", "hits": "0"},
               "hits|ланита|c19": {"key": "hits|ланита|c19", "hits": "41"},
               "freq|вызвать|V": {"key": "freq|вызвать|V", "ipm": "70", "category": "3"},
               "freq|появление|S": {"key": "freq|появление|S", "ipm": "50", "category": "3"}}

    r = lint_gloss("сделал", lem, ev)
    assert r["flags"] == ["inflected"] and r["gloss_lemma"] == "сделать" and r["status"] == "flag", r
    r = lint_gloss("сближении", lem, ev)
    assert r["flags"] == ["inflected", "soft_rare"] and r["gloss_lemma"] == "сближение", r
    r = lint_gloss("ланиты", lem, ev)
    assert "rare" in r["flags"] and "c19_only" in r["flags"], r
    syn = propose_synonym("ланиты", ["ланиты", "щёки", "два слова"], lem, ev, r["min_cat"])
    assert syn and syn[0] == "щека" and syn[2] == 3, syn
    r = lint_gloss("вызвавший появление", lem, ev)
    assert r["flags"] == ["phrase"] and r["status"] == "ok", r
    assert "вызвать:V:3" in r["nkrya_tokens"] if "nkrya_tokens" in r else True
    assert r["tokens"][0].startswith("вызвать:V:3"), r["tokens"]
    assert lint_gloss("", lem, ev)["status"] == "empty"
    assert lint_gloss("the state of being", lem, ev)["status"] == "not_russian"
    r = lint_gloss("облако", lem, ev)          # not in cache, offline -> unknown, never "ok"
    assert r["flags"] == ["unknown"] and r["status"] == "flag", r
    ev.save()
    again = Evidence(tmp / "ev.tsv", offline=True)
    assert again.freq("ланита", "S") == {"ipm": 0.4, "category": 1}
    assert again.hits("ланита", "c19") == 41
    print("nkrya_gloss_lint selftest OK (15 checks, offline)")


def import_client():
    src = os.environ.get("NKRYA_CLIENT_SRC") or str(
        REPO.parent / "SanskritLexicography" / "RussianTranslation" / "src")
    sys.path.insert(0, src)
    import nkrya_client
    return nkrya_client


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("presets", nargs="*", choices=[[], *PRESETS], default=[])
    ap.add_argument("--tsv", nargs="+", default=[],
                    help="seed files (.tsv tab / .csv comma), e.g. database/seeders/data/memrise_X/level_*.csv")
    ap.add_argument("--gloss-col", default="gloss_ru")
    ap.add_argument("--id-cols", default="")
    ap.add_argument("--name", default="custom")
    ap.add_argument("--offline", action="store_true", help="evidence cache only, no network")
    ap.add_argument("--interval", type=float, default=12.0,
                    help="seconds between live NKRYa calls (default 12 = the 5/min account limit)")
    ap.add_argument("--backoff", type=float, default=30.0, help="seconds to wait after a 429")
    ap.add_argument("--limit", type=int, help="first N rows only (smoke)")
    ap.add_argument("--out-dir", default=str(OUT_DIR))
    ap.add_argument("--selftest", action="store_true")
    a = ap.parse_args(argv)
    if a.selftest:
        selftest()
        return 0
    jobs = [(p, PRESETS[p]) for p in (a.presets or [])]
    for path in a.tsv:
        stem = "%s_%s" % (a.name, Path(path).stem) \
            if len(a.tsv) > 1 else a.name
        jobs.append((stem, {"path": os.path.relpath(os.path.abspath(path), REPO),
                            "gloss": a.gloss_col,
                            "ids": [c for c in a.id_cols.split(",") if c],
                            "sa_key": "", "sa_kind": "iast"}))
    if not jobs:
        ap.error("name a preset (roots, lemmas) or --tsv")
    client = None
    if not a.offline:
        nk = import_client()
        nk.MIN_INTERVAL_S = a.interval
        nk.MAX_RETRIES = 0       # 429 is handled by Evidence._live's 65 s back-off, not 2/4/8 s bursts
        client = nk.NkryaClient(cache_dir=str(RAW_CACHE))
    else:
        import_client()          # NkryaError type for the offline path stays importable
    ev = Evidence(EVIDENCE_TSV, client=client, offline=a.offline, backoff=a.backoff)
    lem = Lemmatizer()
    pool = load_pool()
    report = {}
    try:
        for name, spec in jobs:
            out, rows = lint_file(name, spec, lem, ev, pool, out_dir=a.out_dir, limit=a.limit)
            report[name] = summarize(name, rows)
            print("%s -> %s" % (name, os.path.relpath(out, REPO)))
    finally:
        if not a.offline:
            ev.save()
    report["_evidence"] = {"cached_keys": len(ev.rows), "live_lookups": ev.live,
                           "offline_misses": ev.misses, "http_429_backoffs": ev.rate_limited}
    print(json.dumps(report, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
