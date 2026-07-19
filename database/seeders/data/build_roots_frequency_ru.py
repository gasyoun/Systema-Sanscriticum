"""H1280 -- join kosha roots_frequency.tsv (DCS token frequency, H950) against
WhitneyRoots' RU root-gloss layer (H347) to build the fixture this repo's
SrsRootFrequencyDeckSeeder consumes.

Consume-only, per PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md D4:
this script re-derives NEITHER the frequency ranking nor the RU glosses -- it
only joins two already-shipped sibling datasets on the bare IAST root string
(kosha's `dcs_lemma` == WhitneyRoots' `root_iast`; both are the un-numbered
root form, so homonym-shared Whitney entries collapse onto one gloss row --
verified identical across their `whitney_no` duplicates in ru_root_glosses.tsv).

`root_devanagari` is the one derived field: neither sibling dataset ships a
Devanagari root form, so it is rendered here from `dcs_lemma` via the
`indic_transliteration` package (proper virama/conjunct shaping -- unlike
sanskrit-util's `iast_to_devanagari`, which is explicitly display-only and
mangles consonant-final roots like "vac").

Run from a checkout with kosha and WhitneyRoots as sibling directories:
    python database/seeders/data/build_roots_frequency_ru.py

Regenerate whenever either source dataset ships a new version.
"""
from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

from indic_transliteration import sanscript

sys.stdout.reconfigure(encoding="utf-8")

REPO_ROOT = Path(__file__).resolve().parents[3]
KOSHA_JSON = REPO_ROOT.parent / "kosha" / "data" / "roots" / "roots_frequency.json"
RU_GLOSSES_TSV = REPO_ROOT.parent / "WhitneyRoots" / "crosswalk" / "ru_root_glosses.tsv"
OUT_TSV = Path(__file__).resolve().parent / "roots_frequency_ru.tsv"
OUT_UNMATCHED = Path(__file__).resolve().parent / "roots_frequency_ru_unmatched.tsv"

FIELDS = [
    "rank",
    "root_iast",
    "root_devanagari",
    "dcs_lemma",
    "grammar_class",
    "attested_count",
    "coverage_pct",
    "top_form",
    "gloss_ru",
    "source",
]


def load_ru_glosses() -> dict[str, dict]:
    glosses: dict[str, dict] = {}
    with RU_GLOSSES_TSV.open(encoding="utf-8") as f:
        lines = [line for line in f if not line.startswith("#")]
    reader = csv.DictReader(lines, delimiter="\t")
    for row in reader:
        root_iast = row["root_iast"]
        gloss = row.get("gloss_ru_1", "").strip()
        if not gloss:
            continue
        n = int(row.get("root_freq_n") or 0)
        existing = glosses.get(root_iast)
        if existing is None or n > existing["n"]:
            glosses[root_iast] = {"gloss": gloss, "n": n}
    return glosses


def main() -> None:
    if not KOSHA_JSON.exists():
        raise SystemExit(f"missing sibling dataset: {KOSHA_JSON}")
    if not RU_GLOSSES_TSV.exists():
        raise SystemExit(f"missing sibling dataset: {RU_GLOSSES_TSV}")

    kosha = json.loads(KOSHA_JSON.read_text(encoding="utf-8"))
    ru_glosses = load_ru_glosses()

    matched_rows = []
    unmatched_rows = []

    for root in kosha["roots"]:
        lemma = root["dcs_lemma"]
        gloss_entry = ru_glosses.get(lemma)
        top_form = root["top_forms"][0]["form"] if root["top_forms"] else ""

        if gloss_entry is None:
            unmatched_rows.append(
                {
                    "rank": root["rank"],
                    "root_iast": root["root_iast"],
                    "dcs_lemma": lemma,
                    "attested_count": root["attested_count"],
                }
            )
            continue

        matched_rows.append(
            {
                "rank": root["rank"],
                "root_iast": root["root_iast"],
                "root_devanagari": sanscript.transliterate(lemma, sanscript.IAST, sanscript.DEVANAGARI),
                "dcs_lemma": lemma,
                "grammar_class": root["grammar_class"],
                "attested_count": root["attested_count"],
                "coverage_pct": root["coverage_pct"],
                "top_form": top_form,
                "gloss_ru": gloss_entry["gloss"],
                "source": "kosha/roots_frequency.json (H950) x WhitneyRoots/crosswalk/ru_root_glosses.tsv (H347)",
            }
        )

    with OUT_TSV.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDS, delimiter="\t")
        writer.writeheader()
        writer.writerows(matched_rows)

    with OUT_UNMATCHED.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=["rank", "root_iast", "dcs_lemma", "attested_count"], delimiter="\t")
        writer.writeheader()
        writer.writerows(unmatched_rows)

    print(f"matched: {len(matched_rows)} -> {OUT_TSV}")
    print(f"unmatched (no RU gloss, logged not dropped): {len(unmatched_rows)} -> {OUT_UNMATCHED}")


if __name__ == "__main__":
    main()
