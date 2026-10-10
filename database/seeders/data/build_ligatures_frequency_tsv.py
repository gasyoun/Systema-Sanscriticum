#!/usr/bin/env python3
"""Build database/seeders/data/ligatures_frequency.tsv (H6393).

Source of truth: public/lila/ligatures/data.js — the committed DCS
ligature-frequency bands (H1281, kosha "dcs-grapheme-frequency";
Digital Corpus of Sanskrit, Oliver Hellwig, CC BY 4.0). The deck is
content-only on the live Saraswati engine (H1280 root-deck pattern):
rank ASC insertion order == frequency order, no engine changes.

Interim confusion twins (deterministic rule, H6393): two top-200
ligatures are confusion partners iff their IAST readings have the same
length, differ in exactly one consonant slot, and that slot differs
within a visually confusable family:
  - aspiration pairs (k/kh g/gh c/ch j/jh t/th d/dh t..au etc.)
  - dental vs retroflex nasal (n/na-rha)
  - dental vs retroflex sibilant (s/sa-rha)
This stands in until the Kiseleva gold set lands (H6392,
ligature-confusion-gold-kiseleva) — when it does, regenerate this TSV
from the gold pairs; the column layout already carries `confusion`.

Run from the repo root:
  python3 database/seeders/data/build_ligatures_frequency_tsv.py
"""

from __future__ import annotations

import re
from pathlib import Path

REPO = Path(__file__).resolve().parents[3]
DATA_JS = REPO / "public" / "lila" / "ligatures" / "data.js"
OUT_TSV = REPO / "database" / "seeders" / "data" / "ligatures_frequency.tsv"

# IAST consonant -> Devanagari letter (for the `components` column).
CONS = [
    ("kh", "ख"), ("gh", "घ"), ("ch", "छ"), ("jh", "झ"),
    ("ṭh", "ठ"), ("ḍh", "ढ"), ("th", "थ"), ("dh", "ध"),
    ("ph", "फ"), ("bh", "भ"),
    ("k", "क"), ("g", "ग"), ("c", "च"), ("j", "ज"), ("ṭ", "ट"),
    ("ḍ", "ड"), ("ṇ", "ण"), ("t", "त"), ("d", "द"), ("n", "न"),
    ("p", "प"), ("b", "ब"), ("m", "म"), ("y", "य"), ("r", "र"),
    ("l", "ल"), ("v", "व"), ("ś", "श"), ("ṣ", "ष"), ("s", "स"),
    ("h", "ह"), ("ṅ", "ङ"), ("ñ", "ञ"),
]

# Visually confusable single-slot families (interim rule, see docstring).
FAMILIES = [
    {"k", "kh"}, {"g", "gh"}, {"c", "ch"}, {"j", "jh"},
    {"ṭ", "ṭh"}, {"ḍ", "ḍh"}, {"t", "th"}, {"d", "dh"},
    {"p", "ph"}, {"b", "bh"},
    {"n", "ṇ"}, {"s", "ṣ"},
]
IN_FAMILY = {c for fam in FAMILIES for c in fam}


def tokenize(iast: str) -> list[str] | None:
    """Split an IAST consonant cluster into consonant tokens."""
    tokens: list[str] = []
    i = 0
    while i < len(iast):
        for cons, _ in CONS:
            if iast.startswith(cons, i):
                tokens.append(cons)
                i += len(cons)
                break
        else:
            return None  # unexpected glyph
    return tokens


def components(iast: str) -> str:
    mapping = dict(CONS)
    return " + ".join(mapping[t] for t in tokenize(iast) or [] if t in mapping)


def are_twins(a: list[str], b: list[str]) -> bool:
    if len(a) != len(b):
        return False
    diffs = [(x, y) for x, y in zip(a, b) if x != y]
    if len(diffs) != 1:
        return False
    x, y = diffs[0]
    return any(x in fam and y in fam for fam in FAMILIES)


def parse_data_js() -> list[dict]:
    text = DATA_JS.read_text(encoding="utf-8")
    band = re.search(r"top200:\s*\[(.*?)\n\s*\]", text, re.S)
    if not band:
        raise SystemExit("top200 band not found in data.js")
    rows = []
    for m in re.finditer(
        r'\{\s*rank:\s*(\d+),\s*devanagari:\s*"([^"]+)",\s*iast:\s*"([^"]+)",'
        r"\s*count:\s*(\d+),\s*pct:\s*([\d.]+)\s*\}",
        band.group(1),
    ):
        rows.append({
            "rank": int(m.group(1)),
            "devanagari": m.group(2),
            "iast": m.group(3),
            "count": int(m.group(4)),
            "pct": m.group(5),
        })
    if len(rows) != 200:
        raise SystemExit(f"expected 200 rows, parsed {len(rows)}")
    return rows


def main() -> None:
    rows = parse_data_js()
    toks = {r["iast"]: tokenize(r["iast"]) for r in rows}
    for iast, t in toks.items():
        if t is None:
            raise SystemExit(f"cannot tokenize IAST cluster: {iast!r}")

    # rank asc == frequency order (canary for the seeder's id-order claim)
    rows.sort(key=lambda r: r["rank"])

    confusion: dict[int, list[str]] = {r["rank"]: [] for r in rows}
    by_rank = {r["rank"]: r for r in rows}
    for i, a in enumerate(rows):
        ta = toks[a["iast"]]
        assert ta is not None
        for b in rows[i + 1:]:
            tb = toks[b["iast"]]
            assert tb is not None
            if are_twins(ta, tb):
                confusion[a["rank"]].append(b["iast"])
                confusion[b["rank"]].append(a["iast"])

    lines = ["rank\tdevanagari\tiast\tcount\tpct\tcomponents\tconfusion"]
    pairs_total = sum(len(v) for v in confusion.values()) // 2
    for r in rows:
        conf = ",".join(sorted(confusion[r["rank"]]))
        lines.append(
            f'{r["rank"]}\t{r["devanagari"]}\t{r["iast"]}\t{r["count"]}\t'
            f'{r["pct"]}\t{components(r["iast"])}\t{conf}'
        )
    OUT_TSV.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"wrote {OUT_TSV}: 200 rows, {pairs_total} confusion pairs")


if __name__ == "__main__":
    main()
