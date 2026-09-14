#!/usr/bin/env python3
"""Push the 59 mapped subhāṣita mp3 from MG's Yandex.Disk to the Systema public
disk (H4474 stage 2 — plan §5.2; rights confirmed by MG 14-09-2026: «все свои»).

Reads the committed audio manifest, filters rows carrying an `is_num` (the same
59 the deck import consumes), downloads each recording from yadisk: (the
self-served rclone/WebDAV remote — Uprava FINDINGS §719), and copies it into
`storage/app/public/srs/subhashita/<audio_id>.mp3` with the normalized
audio_id name the SRS card's `audio` field points at.

Idempotent: files already present with the right size are skipped; the summary
prints pushed/skipped/missing. Run AFTER `php artisan subhashita:import-audio-deck`
(or any time) — the card field already points at these paths, and SrsMedia::url
returns null for missing files until they exist.

    python scripts/subhashita_push_audio.py \
        --manifest resources/data/subhashita_audio_manifest.tsv \
        --public-root storage/app/public \
        [--remote yadisk] [--dry-run]
"""

import argparse
import shutil
import subprocess
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--manifest", required=True, type=Path)
    ap.add_argument("--public-root", default="storage/app/public", type=Path)
    ap.add_argument("--remote", default="yadisk")
    ap.add_argument("--local-source", type=Path, default=None,
                    help="staging dir mirroring the yadisk tree (already-downloaded mp3) — skips the network")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    with args.manifest.open(encoding="utf-8", newline="") as fh:
        rows = [r for r in csv_rows(fh) if r["file"].lower().endswith(".mp3")]

    target_dir = args.public_root / "srs" / "subhashita"
    target_dir.mkdir(parents=True, exist_ok=True)

    pushed = skipped = missing = 0
    for r in rows:
        audio_id = r["audio_id"]
        dest = target_dir / f"{audio_id}.mp3"
        expected_size = int(r["size_bytes"])

        if dest.is_file() and dest.stat().st_size == expected_size:
            skipped += 1
            continue

        if args.dry_run:
            print(f"[dry-run] would push {r['file']} -> {dest}")
            pushed += 1
            continue

        rel = r["file"].replace("\\", "/")

        if args.local_source:
            staged = args.local_source / rel
            if not staged.is_file():
                print(f"MISS {audio_id}: {staged} not in local source")
                missing += 1
                continue
            shutil.copyfile(str(staged), str(dest))
            if dest.stat().st_size != expected_size:
                print(f"SIZE MISMATCH {audio_id}: {dest.stat().st_size} != {expected_size}")
                dest.unlink()
                missing += 1
                continue
            pushed += 1
            continue

        # rclone copy takes the parent DIRECTORY as the source root + --include
        # pattern; a file path as the source root fails on WebDAV ("directory
        # not found") because rclone treats the root as a directory.
        parent = rel.rsplit("/", 1)[0] if "/" in rel else ""
        include = rel.rsplit("/", 1)[1] if "/" in rel else rel
        src = f"{args.remote}:{parent}" if parent else f"{args.remote}:"
        result = subprocess.run(
            ["rclone", "copy", src, str(target_dir),
             "--include", include, "--no-traverse",
             "--temp-dir", str(target_dir / ".tmp")],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            print(f"FAIL {audio_id}: rclone copy {src} [{include}] failed: {result.stderr.strip()[:200]}")
            missing += 1
            continue

        staged = target_dir / Path(r["file"]).name
        if not staged.is_file():
            print(f"MISS {audio_id}: copy succeeded but {staged.name} absent")
            missing += 1
            continue

        shutil.move(str(staged), str(dest))
        if dest.stat().st_size != expected_size:
            print(f"SIZE MISMATCH {audio_id}: {dest.stat().st_size} != {expected_size}")
            dest.unlink()
            missing += 1
            continue
        pushed += 1

    total = len(rows)
    print(f"\nrows={len(rows)} pushed={pushed} skipped(already present)={skipped} failed={missing}")
    if missing:
        return 1
    return 0


def csv_rows(fh):
    import csv

    yield from csv.DictReader(fh, delimiter="\t")


if __name__ == "__main__":
    import os

    raise SystemExit(main())