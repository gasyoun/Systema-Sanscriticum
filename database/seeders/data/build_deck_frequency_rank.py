#!/usr/bin/env python3
"""H7966 — slim SLP1→rank fixtures for mega-deck segmentation.

Source: kosha data/frequency/lemma_frequency.tsv (DCS lemma frequency sidecar,
kosha-lemma-frequency, CC BY-SA 4.0, DOI 10.5281/zenodo.22105641) — column
rank_all. Emits database/seeders/data/deck_frequency_rank/part-001.tsv
(rank 1-500) and part-002.tsv (rank 501-2000): the frequency bands only test
the 500 / 2000 cutoffs, so ranks > 2000 are band-indistinguishable from
"rest" and are dropped. Partitioned at the band boundary to stay under the
repo token-size gate (20,000 B per file). Deterministic, no network.

Usage: python3 build_deck_frequency_rank.py /path/to/lemma_frequency.tsv
"""
import csv
import os
import sys

PARTS = {"part-001.tsv": (1, 500), "part-002.tsv": (501, 2000)}
SRC = sys.argv[1] if len(sys.argv) > 1 else "/Users/mac/Documents/GitHub/kosha/data/frequency/lemma_frequency.tsv"
OUTDIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "deck_frequency_rank")

buf = {name: [] for name in PARTS}
with open(SRC, newline="", encoding="utf-8") as fh:
    for row in csv.DictReader(fh, delimiter="\t"):
        try:
            rank = int(row["rank_all"])
        except (TypeError, ValueError):
            continue
        key = (row.get("lemma_slp1") or "").strip()
        if not key:
            continue
        for name, (lo, hi) in PARTS.items():
            if lo <= rank <= hi:
                buf[name].append((key, rank))
                break

os.makedirs(OUTDIR, exist_ok=True)
for name, rows in buf.items():
    rows.sort(key=lambda r: r[1])
    path = os.path.join(OUTDIR, name)
    with open(path, "w", newline="", encoding="utf-8") as fh:
        fh.write("slp1\trank\n")
        for key, rank in rows:
            fh.write(f"{key}\t{rank}\n")
    print(f"wrote {len(rows)} rows to {path}")
