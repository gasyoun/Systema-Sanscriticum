#!/usr/bin/env python3
"""Review sheet for the NKRYa gloss lint's synonym swaps (H5285).

Reads the lint TSVs written by scripts/nkrya_gloss_lint.py and cuts one review sheet
of at most 10 cards: rows flagged `rare` (NKRYa band 1 / zero ipm) that carry a
`synonym_proposal` from sa_ru_glossary.json. Approve = show the proposed synonym on
the card; Reject = keep the current gloss. Nothing is applied here.

Screening (review-sheet Phase 0-bis): `inflected` rows are resolved deterministically
(dictionary form, grill 23-09-2026 Q2 — no vote); `soft_rare` is a note (Q4); `rare`
rows with no in-data synonym are counted, not carded. Only swap cards reach a human.

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
BLOB = "https://github.com/gasyoun/Systema-Sanscriticum/blob/main/"
SOURCES = {
    "lemmas": ("«Старт чтения» — слова для SRS", "lemma_slp1",
               "resources/data/cohort_start_chteniya/lemmas_for_srs.tsv"),
    "roots": ("Топ-570 корней (колода корней)", "root_iast",
              "database/seeders/data/roots_frequency_ru.tsv"),
}
SHEET_ID = "systema-sanscriticum-nkrya-gloss-lint_rare-swaps_23-09-2026"
MAX_CARDS = 10


def read(name):
    p = LINT_DIR / ("%s_gloss_lint.tsv" % name)
    if not p.exists():
        return []
    with open(p, encoding="utf-8", newline="") as f:
        return list(csv.DictReader(f, delimiter="\t"))


def band_of(tokens, lemma_hint=None):
    """(lemma, band, ipm) of the rarest content token in an nkrya_tokens cell."""
    best = None
    for t in (tokens or "").split():
        lemma, _pos, cat, ipm = (t.split(":") + ["-"] * 4)[:4]
        if cat == "-":
            continue
        rec = (lemma, int(cat), float(ipm) if ipm != "-" else None)
        if best is None or rec[1] < best[1]:
            best = rec
    return best


def screen(all_rows):
    counts = {"deterministic": 0, "lookup": 0, "agent": 0, "human": 0}
    cards = []
    for name, rows in all_rows.items():
        for r in rows:
            flags = set(filter(None, r["flags"].split(",")))
            if "inflected" in flags and "rare" not in flags:
                counts["deterministic"] += 1          # dictionary form, no vote (Q2)
            elif "rare" in flags and not r["synonym_proposal"]:
                counts["agent"] += 1                  # rare, no in-data synonym: report only
            elif "rare" in flags and r["synonym_proposal"]:
                cards.append((name, r))
    return counts, cards


def card(name, r, idx):
    label, key_col, src = SOURCES[name]
    key = r.get(key_col, "")
    cur = band_of(r["nkrya_tokens"])
    # stable across regenerations: roots by frequency rank, lemmas by lemma + text locus
    cid = ("roots-%s" % r.get("rank")) if name == "roots" else \
        "lemmas-%s-%s-%s" % (r.get("pack"), key, r.get("locus"))
    e = html.escape
    from csl_pyutil import mark_cyrillic
    cur_txt = ("«%s»: полоса %s, %s ipm" % (cur[0], cur[1], "%.2f" % cur[2] if cur[2] is not None else "0")
               if cur else "нет данных")
    flags = r["flags"].split(",")
    c19 = "только в XIX веке (0 вхождений после 1950 г.)" if "c19_only" in flags else ""
    q = ("<p><b>Что это?</b> Карточка <b>%s</b> в колоде «%s». На обороте сейчас: %s.</p>"
         "<p><b>Что изменится?</b> На обороте будет %s.</p>"
         "<p><b>Почему?</b> Слово %s в Национальном корпусе русского языка встречается реже "
         "1 раза на миллион слов (полоса 1 из 6)%s. Замена — %s, полоса %s из 6 (%s ipm). "
         "Её уже даёт наш корпусный глоссарий для этого же слова.</p>"
         "<p>✓ заменить · ✕ оставить как есть · ⏸ позже</p>") % (
        e(key), e(label), mark_cyrillic("«%s»" % e(r["gloss_ru"])),
        mark_cyrillic("«%s»" % e(r["synonym_proposal"])),
        mark_cyrillic("«%s»" % e(r["gloss_ru"])), (", " + c19) if c19 else "",
        mark_cyrillic("«%s»" % e(r["synonym_proposal"])), e(r["synonym_band"]), e(r["synonym_ipm"]))
    ev = ("<p>НКРЯ (ruscorpora.ru, основной корпус, портрет слова, 23-09-2026): текущее — %s; "
          "предложенное «%s» — полоса %s, %s ipm.%s</p>"
          "<p>Синоним взят из <a href=\"%sresources/data/sa_ru_glossary.json\">sa_ru_glossary.json</a> "
          "(топ-3 русских соответствия этой леммы в корпусе DCS). Числа: "
          "<a href=\"%sresources/data/nkrya_lint/nkrya_evidence_cache.tsv\">nkrya_evidence_cache.tsv</a>; "
          "строка: <a href=\"%sresources/data/nkrya_lint/%s_gloss_lint.tsv\">%s_gloss_lint.tsv</a>. "
          "Позиция агента: заменить — в колоде для начинающих слово полосы 1 мешает запоминанию.</p>") % (
        e(cur_txt), e(r["synonym_proposal"]), e(r["synonym_band"]), e(r["synonym_ipm"]),
        (" " + c19.capitalize() + ".") if c19 else "", BLOB, BLOB, BLOB, name, name)
    src_row = "<p>Источник строки: <a href=\"%s%s\">%s</a> · флаги: %s</p>" % (
        BLOB, src, e(src), e(r["flags"]))
    item = {"id": cid, "filt": name,
            "title": "%s: «%s» → «%s»?" % (key, r["gloss_ru"], r["synonym_proposal"]),
            "title_href": "%sresources/data/nkrya_lint/%s_gloss_lint.tsv" % (BLOB, name),
            "badges": ["полоса %s → %s" % (cur[1] if cur else "?", r["synonym_band"])]
            + (["XIX век"] if c19 else []),
            "question": q,
            "panels": [("Evidence", ev), ("Строка", src_row)],
            "note_placeholder": "своя формулировка, если обе не годятся"}
    stamp = {"verifier": "Claude Code Opus 5.5 (claude-opus-5-5)", "method": "corpus_number",
             "sources": ["ruscorpora.ru API word-portrait PORTRAIT_FREQUENCY",
                         "resources/data/nkrya_lint/nkrya_evidence_cache.tsv",
                         "resources/data/sa_ru_glossary.json"],
             "verified_date": "23-09-2026"}
    return item, stamp


def build(out):
    from csl_pyutil import render_review_sheet
    all_rows = {n: read(n) for n in SOURCES}
    counts, cand = screen(all_rows)
    cand.sort(key=lambda nr: (nr[0] != "lemmas", -float(nr[1]["synonym_ipm"] or 0)))
    picked = cand[:MAX_CARDS]
    items, stamps = [], {}
    for i, (name, r) in enumerate(picked, 1):
        it, st = card(name, r, i)
        items.append(it)
        stamps[it["id"]] = st
    counts["human"] = len(items)
    overflow = len(cand) - len(items)
    evidence_path = "resources/data/nkrya_lint/NKRYA_GLOSS_LINT_SCREENING_23-09-2026.md"
    config = {
        "sheet_id": SHEET_ID,
        "title": "НКРЯ: редкие слова на карточках для начинающих — заменить?",
        "subtitle": ("%d карточек. Слова полосы 1 (реже 1 на миллион) на обороте SRS-карточек; "
                     "замена — более частый синоним из нашего же глоссария.%s") % (
            len(items), (" Ещё %d таких строк — в следующем листе." % overflow) if overflow else ""),
        "footer": "H5285 · nkrya_gloss_lint.py · Claude Code Opus 5.5 (claude-opus-5-5)",
        "approve_label": "Заменить", "reject_label": "Оставить",
        "filters": [(n, SOURCES[n][0]) for n in SOURCES],
        "generated": "23-09-2026",
        "show_ids": True, "note_min_height_px": 88,
        # card ids are the Sanskrit lemma itself (SLP1/IAST); the only opaque ids a card could
        # mention are handoff numbers — none do, and the gate would demand a label if one did
        "identity_gate": {"patterns": [r"\bH\d{3,5}\b"], "labels": {}},
        "save_as": "Systema-Sanscriticum/review/%s_decisions.json" % SHEET_ID,
    }
    screening = {**counts, "evidence_path": evidence_path,
                 "rules": ["inflected->dictionary-form (Q2, no vote)",
                           "rare-without-in-data-synonym (report only)",
                           "soft_rare band 2 (note only, Q4)"]}
    page = render_review_sheet(items, config, screening=screening)
    page = page.replace("</body>", "<!-- ssb-evidence: %s -->\n</body>" %
                        json.dumps(stamps, ensure_ascii=False))
    Path(out).parent.mkdir(parents=True, exist_ok=True)
    Path(out).write_text(page, encoding="utf-8")
    return {"cards": len(items), "candidates": len(cand), "screening": counts, "out": str(out)}


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--out", default=str(REPO / "review" / (SHEET_ID + "_review.html")))
    a = ap.parse_args(argv)
    print(json.dumps(build(a.out), ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
