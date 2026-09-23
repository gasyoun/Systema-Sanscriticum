#!/usr/bin/env python3
"""Review sheet for the NKRYa gloss lint's rare-word fixes (H5285).

Reads the lint TSVs written by scripts/nkrya_gloss_lint.py and cuts one review sheet
of at most 10 cards. Three card kinds, all about rows flagged `rare` (NKRYa band 1 /
zero ipm) plus one policy question:

  swap    — a more frequent synonym already in sa_ru_glossary.json (`synonym_proposal`)
  reword  — no in-data synonym; the agent's wording from reword_proposals.tsv, banded
            by the same NKRYa evidence cache (`nkrya_gloss_lint.py --measure`)
  policy  — one systemic question the flagged rows share (a-/an- negation glosses)

Approve = show the proposal on the card; Reject = keep the current gloss. Nothing is
applied here — the seeds are generated fixtures; the upstream feeds carry the fix.

Screening (review-sheet Phase 0-bis): `inflected` rows are resolved deterministically
(dictionary form, grill 23-09-2026 Q2 — no vote); `inflected_ambiguous` rows are resolved
by the Sanskrit part of speech (lookup); `soft_rare` is a note (Q4); `rare` rows with no
proposal of either kind are counted, not carded. Only the cards reach a human.

  python scripts/nkrya_gloss_lint_sheet.py --out review/<sheet_id>_review.html
"""

import argparse
import csv
import html
import json
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

REPO = Path(__file__).resolve().parent.parent
LINT_DIR = REPO / "resources" / "data" / "nkrya_lint"
PROPOSALS = LINT_DIR / "reword_proposals.tsv"
BLOB = "https://github.com/gasyoun/Systema-Sanscriticum/blob/main/"
REPORT = "docs/NKRYA_GLOSS_LINT_BEGINNER_SRS_REPORT_23-09-2026.md"
SOURCES = {
    "lemmas": ("«Старт чтения» — слова для SRS", "lemma_slp1",
               "resources/data/cohort_start_chteniya/lemmas_for_srs.tsv"),
    "roots": ("Топ-570 корней (колода корней)", "root_iast",
              "database/seeders/data/roots_frequency_ru.tsv"),
}
SHEET_ID = "systema-sanscriticum-nkrya-gloss-lint_rare-swaps_23-09-2026"
MAX_CARDS = 10
VERIFIER = "Claude Code Opus 5.5 (claude-opus-5-5)"
NEG_PREFIX = ("не", "бес", "без")
FLAG_RU = {"inflected": "не в словарной форме", "inflected_ambiguous": "форма неоднозначна",
           "phrase": "фраза", "rare": "полоса 1", "soft_rare": "полоса 2",
           "c19_only": "только XIX век", "unknown": "не всё измерено",
           "not_russian": "не по-русски"}


def flags_ru(flags):
    return ", ".join(FLAG_RU.get(f, f) for f in flags.split(",") if f)


def read(name):
    p = LINT_DIR / ("%s_gloss_lint.tsv" % name)
    if not p.exists():
        return []
    with open(p, encoding="utf-8", newline="") as f:
        return list(csv.DictReader(f, delimiter="\t"))


def read_proposals(path=PROPOSALS):
    if not Path(path).exists():
        return {}
    with open(path, encoding="utf-8", newline="") as f:
        return {(r["source"], r["key"], r["gloss_ru"]): r
                for r in csv.DictReader(f, delimiter="\t")}


def rarest(tokens):
    """(lemma, band, ipm) of the rarest measured token in an nkrya_tokens cell."""
    best = None
    for t in (tokens or "").split():
        lemma, _pos, cat, ipm = (t.split(":") + ["-"] * 4)[:4]
        if cat == "-":
            continue
        rec = (lemma, int(cat), float(ipm) if ipm != "-" else None)
        if best is None or rec[1] < best[1]:
            best = rec
    return best


def measure(gloss):
    """Band a proposal from the committed evidence cache only (never the network)."""
    sys.path.insert(0, str(REPO / "scripts"))
    import nkrya_gloss_lint as L
    ev = L.Evidence(L.EVIDENCE_TSV, client=None, offline=True)
    r = L.lint_gloss(gloss, L.Lemmatizer(), ev)
    lo = rarest(" ".join(r["tokens"]))
    return {"band": r["min_cat"], "tokens": r["tokens"], "rarest": lo,
            "complete": ev.misses == 0}


def screen(all_rows, proposals):
    counts = {"deterministic": 0, "lookup": 0, "agent": 0, "human": 0}
    cards, negation = [], []
    for name, rows in all_rows.items():
        key_col = SOURCES[name][1]
        for r in rows:
            flags = set(filter(None, r["flags"].split(",")))
            low = r["gloss_ru"].strip().lower()
            if flags & {"rare", "soft_rare"} and low.startswith(NEG_PREFIX):
                negation.append((name, r))
            if "rare" in flags:
                prop = proposals.get((name, r.get(key_col, ""), r["gloss_ru"]))
                if r["synonym_proposal"]:
                    cards.append(("swap", name, r, None))
                elif prop:
                    cards.append(("reword", name, r, prop))
                else:
                    counts["agent"] += 1          # rare, nothing to propose yet: report only
            elif "soft_rare" in flags:
                counts["agent"] += 1              # band 2: a note, no vote (Q4)
            elif "inflected_ambiguous" in flags:
                counts["lookup"] += 1             # resolved by the Sanskrit part of speech
            elif "inflected" in flags:
                counts["deterministic"] += 1      # dictionary form, no vote (Q2)
    return counts, cards, negation


def card_id(name, r):
    # stable across regenerations: roots by frequency rank, lemmas by lemma + text locus
    if name == "roots":
        return "roots-%s" % r.get("rank")
    return "lemmas-%s-%s-%s" % (r.get("pack"), r.get("lemma_slp1"), r.get("locus"))


def fmt_band(rec):
    if not rec:
        return "нет в корпусе (0 ipm)"
    return "«%s»: полоса %s из 6, %s ipm" % (rec[0], rec[1],
                                             "%.2f" % rec[2] if rec[2] is not None else "0")


def row_card(kind, name, r, prop):
    from csl_pyutil import mark_cyrillic
    e = html.escape
    label, key_col, src = SOURCES[name]
    key = r.get(key_col, "")
    cur = rarest(r["nkrya_tokens"])
    flags = r["flags"].split(",")
    c19 = "только в XIX веке (0 вхождений после 1950 г.)" if "c19_only" in flags else ""
    if kind == "swap":
        new, band = r["synonym_proposal"], r["synonym_band"]
        new_txt = "полоса %s из 6, %s ipm" % (band, r["synonym_ipm"])
        origin = ("Её уже даёт наш корпусный глоссарий "
                  "(<a href=\"%sresources/data/sa_ru_glossary.json\">sa_ru_glossary.json</a>) "
                  "для этого же слова." % BLOB)
        stance = "заменить — синоним из наших же данных, и он частотнее"
    else:
        m = measure(prop["proposal"])
        new, band = prop["proposal"], m["band"]
        new_txt = ("самое редкое слово — " + fmt_band(m["rarest"])) if m["rarest"] else "нет данных"
        origin = ("В нашем глоссарии синонима нет, поэтому формулировку предлагает агент: %s."
                  % e(prop["why"]))
        stance = "заменить — формулировка агента, её нужно одобрить"
    cur_txt = fmt_band(cur) if cur else "в НКРЯ не найдено ни одного вхождения"
    q = ("<p><b>Что это?</b> Карточка <b>%s</b> в колоде «%s». На обороте сейчас: %s.</p>"
         "<p><b>Что изменится?</b> На обороте будет %s.</p>"
         "<p><b>Почему?</b> Сейчас: %s%s — для начинающего это слово реже 1 раза на "
         "миллион слов русских текстов. Предложение: %s. %s</p>"
         "<p>✓ заменить · ✕ оставить как есть · ⏸ позже</p>") % (
        e(key), e(label), mark_cyrillic("«%s»" % e(r["gloss_ru"])),
        mark_cyrillic("«%s»" % e(new)), e(cur_txt), (", " + c19) if c19 else "",
        e(new_txt), origin)
    ev = ("<p>НКРЯ (ruscorpora.ru API, основной корпус, «портрет слова», частотность, "
          "23-09-2026). Сейчас: %s. Предложено «%s»: %s.%s</p>"
          "<p>Числа: <a href=\"%sresources/data/nkrya_lint/nkrya_evidence_cache.tsv\">"
          "nkrya_evidence_cache.tsv</a>; строка линта: "
          "<a href=\"%sresources/data/nkrya_lint/%s_gloss_lint.tsv\">%s_gloss_lint.tsv</a>. "
          "Позиция агента: %s.</p>") % (
        e(cur_txt), e(new), e(new_txt), (" " + c19.capitalize() + ".") if c19 else "",
        BLOB, BLOB, name, name, e(stance))
    src_row = "<p>Источник строки: <a href=\"%s%s\">%s</a> · отметки линта: %s</p>" % (
        BLOB, src, e(src), e(flags_ru(r["flags"])))
    item = {"id": card_id(name, r), "filt": name,
            "title": "%s: «%s» → «%s»?" % (key, r["gloss_ru"], new),
            "title_href": "%sresources/data/nkrya_lint/%s_gloss_lint.tsv" % (BLOB, name),
            "badges": ["полоса %s → %s" % (cur[1] if cur else "0", band if band else "?"),
                       "синоним из глоссария" if kind == "swap" else "формулировка агента"]
            + (["XIX век"] if c19 else []),
            "question": q,
            "panels": [("Evidence", ev), ("Строка", src_row)],
            "note_placeholder": "своя формулировка, если обе не годятся"}
    stamp = {"verifier": VERIFIER, "method": "corpus_number",
             "sources": ["ruscorpora.ru API word-portrait PORTRAIT_FREQUENCY",
                         "resources/data/nkrya_lint/nkrya_evidence_cache.tsv",
                         "resources/data/sa_ru_glossary.json" if kind == "swap"
                         else "resources/data/nkrya_lint/reword_proposals.tsv"],
             "verified_date": "23-09-2026"}
    return item, stamp


def policy_card(negation):
    from csl_pyutil import mark_cyrillic
    e = html.escape
    lines = []
    for name, r in negation[:8]:
        cur = rarest(r["nkrya_tokens"])
        lines.append("<li>%s — %s (%s)</li>" % (
            e(r.get(SOURCES[name][1], "")), mark_cyrillic("«%s»" % e(r["gloss_ru"])),
            e(fmt_band(cur) if cur else "нет в корпусе")))
    q = ("<p><b>Что это?</b> Правило для следующего прохода линта, не одна карточка. "
         "Санскритские слова с отрицанием a-/an- («не-», «без-») у нас переведены одним "
         "слитным русским словом на «не-»/«без-». Именно такие слова линт и находит редкими.</p>"
         "<p><b>Что изменится?</b> Если «да»: для таких слов агент в следующем проходе "
         "предлагает формулировку из частых слов («вечно молодой» вместо «нестареющий») "
         "и выносит её на такой же лист. Если «нет»: слитные слова остаются, линт только "
         "помечает их, листов с заменами по ним не будет.</p>"
         "<p><b>Почему?</b> Все %d отмеченных пока строк (полосы 1–2) — именно такие. "
         "Если значение складывается из двух частых слов, начинающему легче: оба слова "
         "он уже знает.</p><ul>%s</ul>"
         "<p>✓ да, предлагать · ✕ нет, только помечать · ⏸ позже</p>") % (
        len(negation), "".join(lines))
    ev = ("<p>Строки линта с флагом <code>rare</code> или <code>soft_rare</code>, у которых "
          "русское слово начинается на «не»/«без»/«бес»: %d. Полосы — НКРЯ, портрет слова, "
          "23-09-2026, <a href=\"%sresources/data/nkrya_lint/nkrya_evidence_cache.tsv\">"
          "nkrya_evidence_cache.tsv</a>. Проход неполный: измерена только часть слов, "
          "подробности в <a href=\"%s%s\">отчёте</a>. Позиция агента: да — это самый "
          "частый источник редких слов на карточках.</p>") % (len(negation), BLOB, BLOB, REPORT)
    item = {"id": "policy-negation-glosses", "filt": "lemmas",
            "title": "Правило: слова с отрицанием a-/an- — предлагать формулировку из частых слов?",
            "title_href": BLOB + REPORT,
            "badges": ["правило", "%d строк" % len(negation)],
            "question": q, "panels": [("Evidence", ev)],
            "note_placeholder": "своё правило, если ни одно не подходит"}
    stamp = {"verifier": VERIFIER, "method": "corpus_number",
             "sources": ["resources/data/nkrya_lint/lemmas_gloss_lint.tsv",
                         "resources/data/nkrya_lint/nkrya_evidence_cache.tsv"],
             "verified_date": "23-09-2026"}
    return item, stamp


def build(out):
    from csl_pyutil import render_review_sheet
    all_rows = {n: read(n) for n in SOURCES}
    counts, cand, negation = screen(all_rows, read_proposals())
    # glossary swaps first (no agent wording to trust), then rewordings; lemmas before roots
    cand.sort(key=lambda c: (c[0] != "swap", c[1] != "lemmas",
                             -float(c[2]["synonym_ipm"] or 0)))
    room = MAX_CARDS - (1 if len(negation) >= 3 else 0)
    items, stamps = [], {}
    for kind, name, r, prop in cand[:room]:
        it, st = row_card(kind, name, r, prop)
        items.append(it)
        stamps[it["id"]] = st
    if len(negation) >= 3:
        it, st = policy_card(negation)
        items.append(it)
        stamps[it["id"]] = st
    counts["human"] = len(items)
    from csl_pyutil.evidence import EvidenceManifest
    manifest = EvidenceManifest(SHEET_ID, [it["id"] for it in items], repo_root=str(REPO))
    for name in SOURCES:
        manifest.declare_joined("resources/data/nkrya_lint/%s_gloss_lint.tsv" % name,
                                ["gloss_ru", "flags", "nkrya_tokens", "synonym_proposal"])
    manifest.declare_joined("resources/data/nkrya_lint/nkrya_evidence_cache.tsv",
                            ["ipm", "category", "hits"])
    manifest.declare_joined("resources/data/nkrya_lint/reword_proposals.tsv",
                            ["proposal", "why"])
    manifest.declare_joined("resources/data/sa_ru_glossary.json", ["g"])
    for it in items:
        manifest.add_card(it["id"], ["nkrya_band_current", "nkrya_band_proposed"])
    overflow = len(cand) - min(len(cand), room)
    counts["agent"] += overflow        # carded next sheet; counted, not hidden
    config = {
        "sheet_id": SHEET_ID,
        "title": "НКРЯ: редкие слова на карточках для начинающих — заменить?",
        "subtitle": ("%d карточек. Слова полосы 1 (реже 1 на миллион) на обороте SRS-карточек "
                     "и одно правило для слов с отрицанием.%s") % (
            len(items), (" Ещё %d таких строк — в следующем листе." % overflow) if overflow else ""),
        "footer": "H5285 · nkrya_gloss_lint.py · " + VERIFIER,
        "approve_label": "Заменить", "reject_label": "Оставить",
        "filters": [(n, SOURCES[n][0]) for n in SOURCES],
        "generated": "23-09-2026",
        "show_ids": True, "note_min_height_px": 88,
        # card ids are the Sanskrit lemma itself (SLP1/IAST); the only opaque ids a card could
        # mention are handoff numbers — none do, and the gate would demand a label if one did
        "identity_gate": {"patterns": [r"\bH\d{3,5}\b"], "labels": {}},
        "save_as": "Systema-Sanscriticum/review/%s_decisions.json" % SHEET_ID,
        # English lint flag names in the screening banner, not Sanskrit
        "preflight": {"allow_slp1_tokens": ("inflected", "inflected_ambiguous", "soft_rare")},
    }
    screening = {**counts, "evidence_path": BLOB + REPORT,
                 "rules": ["inflected -> dictionary form (grill Q2, no vote)",
                           "inflected_ambiguous -> Sanskrit part of speech (lookup)",
                           "soft_rare band 2 -> note only (grill Q4)",
                           "rare without any proposal -> report only"]}
    page = render_review_sheet(items, config, screening=screening, manifest=manifest)
    page = page.replace("</body>", "<!-- ssb-evidence: %s -->\n</body>" %
                        json.dumps(stamps, ensure_ascii=False))
    Path(out).parent.mkdir(parents=True, exist_ok=True)
    Path(out).write_text(page, encoding="utf-8")
    return {"cards": len(items), "candidates": len(cand), "negation_rows": len(negation),
            "screening": counts, "out": str(out)}


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--out", default=str(REPO / "review" / (SHEET_ID + "_review.html")))
    a = ap.parse_args(argv)
    print(json.dumps(build(a.out), ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
