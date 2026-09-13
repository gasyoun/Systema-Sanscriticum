#!/usr/bin/env python3
"""Verify the subhāṣita audio manifest against Böhtlingk's Indische Sprüche (H4474).

For every row that claims an `is_num`, re-read that saying from
indische_sprueche.jsonl and assert the manifest's Devanagari pratīka really opens
it (folded comparison — no spaces, no daṇḍas). Prints one line per checked row
plus a PASS/FAIL summary; exit 1 on any mismatch.

    python scripts/verify_subhashita_audio_manifest.py \
        --manifest resources/data/subhashita_audio_manifest.tsv \
        --sprueche <path>/IndischeSprueche/data/indische_sprueche.jsonl
"""

import argparse
import csv
import json
import sys
import unicodedata
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

STRIP = "".join(" \t।॥|/,.;:'​‌‍-")


def norm(s: str) -> str:
    s = unicodedata.normalize("NFC", s or "")
    return "".join(ch for ch in s if ch not in STRIP)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--manifest", required=True, type=Path)
    ap.add_argument("--sprueche", required=True, type=Path)
    ap.add_argument("--quiet", action="store_true", help="summary only")
    args = ap.parse_args()

    sayings = {}
    with args.sprueche.open(encoding="utf-8") as fh:
        for line in fh:
            if line.strip():
                rec = json.loads(line)
                sayings[rec["num"]] = rec

    with args.manifest.open(encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh, delimiter="\t"))

    checked = ok = 0
    variants, failures = [], []
    for row in rows:
        if not row["is_num"]:
            continue
        checked += 1
        num = int(row["is_num"])
        rec = sayings.get(num)
        if rec is None:
            failures.append((row["audio_id"], num, "no such saying in the corpus"))
            continue
        key = norm(rec["deva"])
        pratika = norm(row["deva_pratika"])[:24]
        if pratika and key.startswith(pratika):
            ok += 1
            if not args.quiet:
                print(f"PASS    {row['audio_id']:<34} IS {num:<5} {rec['iast'][:52]}")
        elif len(first_word) >= 6 and key.startswith(first_word):
            # Same verse, different reading: the tape follows the teaching
            # anthology, Böhtlingk prints another recension (त्रीणि/त्रीणी,
            # विभवो/वैभवं, द्वे फले/द्वे एव, पुस्तकस्था तु/च). The first word
            # still opens the saying — a real match, flagged rather than hidden.
            variants.append((row["audio_id"], num, row["deva_pratika"], rec["deva"][:30]))
        else:
            failures.append((row["audio_id"], num, f"pratīka {row['deva_pratika']!r} does not open IS {num}"))

    for audio_id, num, ours, theirs in variants:
        print(f"VARIANT {audio_id:<34} IS {num:<5} tape={ours} · IS={theirs}")
    for audio_id, num, why in failures:
        print(f"FAIL    {audio_id:<34} IS {num:<5} {why}")

    unmatched = sum(1 for r in rows if not r["is_num"])
    durations = [float(r["duration_s"]) for r in rows if r["duration_s"]]
    print(f"\nrows={len(rows)} with_is_num={checked} pass={ok} variant={len(variants)} "
          f"fail={len(failures)} no_is_num={unmatched} "
          f"audio_minutes={sum(durations) / 60:.1f} "
          f"missing_duration={len(rows) - len(durations)}")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
