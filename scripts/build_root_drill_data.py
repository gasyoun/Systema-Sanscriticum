"""H1356 -- build the frequency-banded root-drill data.js from the already-committed
570-root RU fixture (database/seeders/data/roots_frequency_ru.tsv, H1280).

Consume-only, mirrors the ligatures family (D6, H1281) top-N cumulative-band
shape, but this generator is COMMITTED here (the ligatures equivalent,
gen_ligature_data.py, was never committed -- PLAN_SYSTEMA_CORPUS_FREQUENCY_
LEARNER_SURFACES_2026H2.meta.md flags that as a gap; H1356 closes it for roots).

Does not re-derive frequency ranking or RU glosses -- those come from the
fixture as-is (kosha roots_frequency.json x WhitneyRoots ru_root_glosses.tsv).
Rows with an empty gloss_ru are skipped defensively, though the committed
fixture already excludes them (the 60 unmatched rows live in the sibling
roots_frequency_ru_unmatched.tsv, never in the main fixture).

Run from the repo root:
    python scripts/build_root_drill_data.py

Use --check to verify public/lila/roots/data.js is up to date with the
fixture without writing (exits non-zero on drift; for CI/pre-merge use).

Regenerate whenever database/seeders/data/roots_frequency_ru.tsv is rebuilt
(database/seeders/data/build_roots_frequency_ru.py).
"""
from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

REPO_ROOT = Path(__file__).resolve().parents[1]
FIXTURE_TSV = REPO_ROOT / "database" / "seeders" / "data" / "roots_frequency_ru.tsv"
OUT_JS = REPO_ROOT / "public" / "exercises" / "roots" / "data.js"

BANDS = {"top25": 25, "top50": 50, "top100": 100}


def load_rows() -> list[dict]:
    with FIXTURE_TSV.open(encoding="utf-8") as f:
        rows = [r for r in csv.DictReader(f, delimiter="\t") if r["gloss_ru"].strip()]
    ranks = [int(r["rank"]) for r in rows]
    assert ranks == sorted(ranks), "fixture must already be rank-sorted"
    return rows


def to_band_row(row: dict) -> dict:
    return {
        "rank": int(row["rank"]),
        "devanagari": row["root_devanagari"],
        "iast": row["root_iast"],
        "gloss_ru": row["gloss_ru"],
        "top_form": row["top_form"],
        "grammar_class": row["grammar_class"],
        "coverage_pct": float(row["coverage_pct"]),
    }


def render(bands: dict) -> str:
    lines = []
    lines.append("/* ============================================================")
    lines.append("   Root-frequency data for the root drill (H1356).")
    lines.append("")
    lines.append("   Source: database/seeders/data/roots_frequency_ru.tsv (H1280) --")
    lines.append("   kosha roots_frequency.json (DCS token frequency, H950) joined")
    lines.append("   against WhitneyRoots ru_root_glosses.tsv (RU glosses, H347) by")
    lines.append("   database/seeders/data/build_roots_frequency_ru.py. Same fixture")
    lines.append("   already ships as the D4 SRS deck (H1280, SrsRootFrequencyDeckSeeder).")
    lines.append("")
    lines.append("   Bands are CUMULATIVE frequency-rank slices (top25 subset-of top50")
    lines.append("   subset-of top100), rank 1 = most frequent root by DCS token count.")
    lines.append("   coverage_pct = cumulative % of all root-tagged tokens covered up")
    lines.append("   to and including this rank.")
    lines.append("")
    lines.append("   Regenerate: python scripts/build_root_drill_data.py")
    lines.append("   (after refreshing database/seeders/data/roots_frequency_ru.tsv)")
    lines.append("   ============================================================ */")
    lines.append("(function (global) {")
    lines.append('  "use strict";')
    lines.append("  global.ROOT_BANDS = " + json.dumps(bands, ensure_ascii=False, indent=2) + ";")
    lines.append("  Object.freeze(global.ROOT_BANDS);")
    lines.append("})(this);")
    return "\n".join(lines) + "\n"


def build_bands() -> dict:
    rows = load_rows()
    if len(rows) < max(BANDS.values()):
        raise SystemExit(f"fixture has only {len(rows)} usable rows, need >= {max(BANDS.values())}")
    return {name: [to_band_row(r) for r in rows[:n]] for name, n in BANDS.items()}


def main() -> None:
    check = "--check" in sys.argv[1:]
    bands = build_bands()
    rendered = render(bands)

    if check:
        current = OUT_JS.read_text(encoding="utf-8") if OUT_JS.exists() else None
        if current != rendered:
            print(f"DRIFT: {OUT_JS} is stale relative to {FIXTURE_TSV}")
            raise SystemExit(1)
        print(f"OK: {OUT_JS} matches the fixture")
        return

    OUT_JS.write_text(rendered, encoding="utf-8")
    print(f"wrote {OUT_JS} ({sum(len(v) for v in bands.values())} band rows across {len(bands)} bands)")


if __name__ == "__main__":
    main()
