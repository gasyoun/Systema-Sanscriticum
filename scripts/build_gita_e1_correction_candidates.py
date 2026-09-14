#!/usr/bin/env python3
"""H4717 — Gita inflection-QA divergence ledger -> E1 hybrid-forms correction candidates.

Reads kosha's `gita-inflection-qa` ledger (data/gita/gita_inflection_divergences.tsv:
DIVERGE = kosha has the form but not the gold case/number cell; GAP = form absent
from kosha `inflections`) and triages every row into a candidate class with a
proposed action, for HUMAN review. Nothing is written to kosha.db or to any
correction file — the `decision` column is left empty for the reviewer.

Read-only inputs:
  --ledger    kosha data/gita/gita_inflection_divergences.tsv (take it from
              origin/main: `git -C ../kosha show origin/main:data/gita/... > f`)
  --gold      kosha data/gita/gita_morphology_gold.tsv (adds pos; optional)
  --manifest  kosha data/manifest/datasets.json (row-count parity; optional)
  --db        kosha data/db/kosha.db (enrichment + re-derivation parity; optional,
              opened mode=ro)

Output: docs/evidence/gita_e1_correction_candidates_H4717.tsv (+ JSON summary on
stdout). Exit 1 if a ledger-parity check fails.
"""
from __future__ import annotations

import argparse
import csv
import json
import sqlite3
import sys
from collections import Counter
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parent.parent
GH = ROOT.parent
KOSHA = GH / "kosha"
OUT = ROOT / "docs" / "evidence" / "gita_e1_correction_candidates_H4717.tsv"

CASE = {"nom": "nom", "acc": "acc", "ins": "instr", "instr": "instr", "dat": "dat",
        "abl": "abl", "gen": "gen", "loc": "loc", "voc": "voc"}  # gold -> kosha gcase

# Pausa restorations for a sandhied surface form (IAST final -> pausa final).
PAUSA = [("ṁ", "m"), ("ṁ", "n"), ("ṃ", "m"), ("d", "t"), ("g", "k"), ("b", "p"),
         ("ḍ", "ṭ"), ("o", "aḥ"), ("r", "ḥ"), ("ś", "ḥ"), ("s", "ḥ"), ("ṣ", "ḥ"),
         ("ñ", "n"), ("l", "t"), ("c", "t"), ("j", "t"), ("n", "t")]

ACTION = {
    "D1_kosha_null_cells": "kosha: existing row has NULL case/gender — fill the cell or flag disputed=1",
    "D2_number_mismatch": "review: gold number absent from kosha's analyses — gold mislabel OR missing number in paradigm",
    "D3_case_mismatch": "review: same number, gold case absent — gold mislabel (syncretic ending) OR kosha paradigm cell gap",
    "G0_sandhi_surface": "none: gold form is a sandhied surface; the pausa form carries the gold cell in kosha",
    "G1_compound_member_covered": "none: compound; final member carries the gold cell in kosha (compounds are out of paradigm scope)",
    "G2_compound_member_gap": "gap-fill candidate: compound's final member lacks the gold cell — review member paradigm",
    "G3_lemma_absent": "gap-fill candidate: lemma has no paradigm in kosha inflections — generate paradigm (e.g. vidyut-gap-fill)",
    "G4_cell_absent": "gap-fill candidate: lemma paradigm exists but this form/cell is missing — add variant",
}


def load_to_slp1(util: Path):
    sys.path.insert(0, str(util))
    from sanskrit_util import to_slp1  # noqa: E402
    return to_slp1


def gold_cell(g: str):
    parts = (g.split("/") + ["", "", ""])[:3]
    case, number, gender = parts
    return CASE.get(case, case), number, gender


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--ledger", type=Path, default=KOSHA / "data" / "gita" / "gita_inflection_divergences.tsv")
    ap.add_argument("--gold", type=Path, default=KOSHA / "data" / "gita" / "gita_morphology_gold.tsv")
    ap.add_argument("--manifest", type=Path, default=KOSHA / "data" / "manifest" / "datasets.json")
    ap.add_argument("--db", type=Path, default=KOSHA / "data" / "db" / "kosha.db")
    ap.add_argument("--sanskrit-util", type=Path, default=GH / "sanskrit-util" / "py")
    ap.add_argument("--out", type=Path, default=OUT)
    a = ap.parse_args()

    to_slp1 = load_to_slp1(a.sanskrit_util)

    def clean(form: str) -> str:  # identical to kosha scripts/gita_inflection_qa.py clean()
        return to_slp1(form.replace("-", "").strip().strip("'’"))

    ledger = list(csv.DictReader(open(a.ledger, encoding="utf-8"), delimiter="\t"))

    pos = {}
    if a.gold.exists():
        for r in csv.DictReader(open(a.gold, encoding="utf-8"), delimiter="\t"):
            pos.setdefault((r["verse"], r["form"]), r.get("pos", ""))

    con = None
    if a.db.exists():
        con = sqlite3.connect(f"file:{a.db.as_posix()}?mode=ro", uri=True)

    def analyses(form_slp1: str):
        return set(con.execute("SELECT gcase, number, gender FROM inflections WHERE form_slp1=?",
                               (form_slp1,)))

    def lemma_rows(lemma_slp1: str) -> int:
        return con.execute("SELECT COUNT(*) FROM inflections WHERE lemma_slp1=?",
                           (lemma_slp1,)).fetchone()[0]

    def has_cell(form_iast: str, gc) -> bool:
        return any(x[0] == gc[0] and x[1] == gc[1] for x in analyses(clean(form_iast)))

    def pausa_hit(form_iast: str, gc):
        for fin, rep in PAUSA:
            if form_iast.endswith(fin):
                cand = form_iast[: -len(fin)] + rep
                if has_cell(cand, gc):
                    return cand
        return None

    rows, classes, parity_miss = [], Counter(), []
    for r in ledger:
        gc = gold_cell(r["gold_case_num_gender"])
        cls, note = "", ""
        if con is not None:
            an = analyses(clean(r["form"]))
            # re-derivation parity: the ledger class must still hold on this db
            if r["class"] == "GAP" and an:
                parity_miss.append((r["verse"], r["form"], "GAP but form now in kosha"))
            if r["class"] == "DIVERGE" and (not an or any(x[0] == gc[0] and x[1] == gc[1] for x in an)):
                parity_miss.append((r["verse"], r["form"], "DIVERGE no longer diverges"))
        if r["class"] == "DIVERGE":
            kosha = [x.split(".") for x in r["kosha_analyses"].split(";") if x]
            if any("None" in x for x in kosha):
                cls = "D1_kosha_null_cells"
            elif gc[1] not in {x[1] for x in kosha if len(x) > 1}:
                cls = "D2_number_mismatch"
            else:
                cls = "D3_case_mismatch"
        elif con is None:
            cls = "G?_unenriched"
        else:
            compound = "-" in r["lemma"]
            member = r["form"].split("-")[-1] if "-" in r["form"] else r["form"]
            hit = pausa_hit(r["form"], gc) or (compound and "-" in r["form"] and pausa_hit(member, gc))
            if hit:
                cls, note = "G0_sandhi_surface", f"pausa form: {hit}"
            elif compound:
                if "-" in r["form"] and has_cell(member, gc):
                    cls, note = "G1_compound_member_covered", f"member: {member}"
                else:
                    cls, note = "G2_compound_member_gap", f"member: {member}"
            else:
                n = lemma_rows(clean(r["lemma"]))
                cls = "G4_cell_absent" if n else "G3_lemma_absent"
                note = f"lemma rows in kosha: {n}"
        classes[cls] += 1
        rows.append([r["verse"], r["form"], r["lemma"], pos.get((r["verse"], r["form"]), ""),
                     r["gold_case_num_gender"], r["class"], r["kosha_analyses"], cls,
                     ACTION.get(cls, ""), note, ""])

    a.out.parent.mkdir(parents=True, exist_ok=True)
    with open(a.out, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh, delimiter="\t", lineterminator="\n")
        w.writerow(["verse", "form", "lemma", "gold_pos", "gold_case_num_gender", "ledger_class",
                    "kosha_analyses", "candidate_class", "proposed_action", "evidence", "decision"])
        w.writerows(rows)

    # ---- ledger parity -------------------------------------------------------------
    checks = {}
    key = lambda x: (x[0], x[1], x[2], x[3])  # noqa: E731
    led_keys = Counter((r["verse"], r["form"], r["lemma"], r["gold_case_num_gender"]) for r in ledger)
    out_keys = Counter(key([x[0], x[1], x[2], x[4]]) for x in rows)
    checks["rows_out_eq_ledger"] = len(rows) == len(ledger)
    checks["keys_out_eq_ledger"] = led_keys == out_keys
    led_cls = Counter(r["class"] for r in ledger)
    checks["class_split_preserved"] = led_cls == Counter(x[5] for x in rows)
    if a.manifest.exists():
        man = json.load(open(a.manifest, encoding="utf-8"))
        ds = man if isinstance(man, list) else man.get("datasets", [])
        row = next((d for d in ds if d.get("id") == "gita-inflection-qa"), {})
        checks["ledger_eq_manifest_rows"] = row.get("rows") == len(ledger)
    if con is not None:
        checks["ledger_rederives_on_db"] = not parity_miss

    summary = {
        "ledger_rows": len(ledger), "ledger_classes": dict(led_cls),
        "candidate_classes": dict(sorted(classes.items())), "checks": checks,
        "parity_misses": parity_miss[:20], "db": str(a.db) if con else None,
        "out": str(a.out.relative_to(ROOT)) if a.out.is_relative_to(ROOT) else str(a.out),
    }
    print(json.dumps(summary, ensure_ascii=False, indent=1))
    return 0 if all(checks.values()) else 1


if __name__ == "__main__":
    sys.exit(main())
