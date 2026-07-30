#!/usr/bin/env python3
"""Validate an Anki→SRS export directory (manifest.json + level CSVs).

Mirrors scripts/memrise_export_validate.py: no network, no credentials.
Exit 0 on contract OK; non-zero with a clear message on failure.

Usage:
    python scripts/anki_export_validate.py database/seeders/data/anki_454628379
    python scripts/anki_export_validate.py tests/fixtures/anki_sample
"""
from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

REQUIRED_MANIFEST = ("course_id", "course_name", "columns", "levels")
# At least one identity column + translation
RECOMMENDED_COLUMNS = ("devanagari", "iast", "translation")


def fail(msg: str) -> int:
    print(f"FAIL: {msg}", file=sys.stderr)
    return 1


def main(argv: list[str] | None = None) -> int:
    args = argv if argv is not None else sys.argv[1:]
    if not args:
        return fail("usage: anki_export_validate.py <export_dir>")

    root = Path(args[0])
    if not root.is_dir():
        return fail(f"not a directory: {root}")

    man_path = root / "manifest.json"
    if not man_path.is_file():
        return fail(f"manifest.json missing under {root}")

    try:
        manifest = json.loads(man_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        return fail(f"manifest.json not valid JSON: {e}")

    for key in REQUIRED_MANIFEST:
        if key not in manifest:
            return fail(f"manifest missing key: {key}")

    columns = manifest["columns"]
    if not isinstance(columns, dict) or not columns:
        return fail("manifest.columns must be a non-empty object")

    if "translation" not in columns and "translation" not in columns.values():
        # allow either field-name keys or header values
        if "translation" not in set(columns.keys()) | set(columns.values()):
            print("WARN: no 'translation' column mapping", file=sys.stderr)

    levels = manifest["levels"]
    if not isinstance(levels, list) or not levels:
        return fail("manifest.levels must be a non-empty list")

    total_rows = 0
    for level in levels:
        for k in ("index", "name", "file"):
            if k not in level:
                return fail(f"level missing key {k}: {level}")
        csv_path = root / level["file"]
        if not csv_path.is_file():
            return fail(f"level file missing: {csv_path}")

        with csv_path.open(encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            if not reader.fieldnames:
                return fail(f"empty CSV header: {csv_path}")
            header = list(reader.fieldnames)
            for field, csv_header in columns.items():
                if csv_header not in header:
                    return fail(
                        f"CSV {csv_path.name} missing header {csv_header!r} "
                        f"(mapped from field {field!r}); have {header}"
                    )
            rows = list(reader)
            if not rows:
                return fail(f"CSV has no data rows: {csv_path}")
            total_rows += len(rows)

            # Spot-check: at least one of devanagari/iast non-empty in first row
            first = rows[0]
            dev_h = columns.get("devanagari", "devanagari")
            iast_h = columns.get("iast", "iast")
            tr_h = columns.get("translation", "translation")
            if not (first.get(dev_h) or first.get(iast_h)):
                return fail(
                    f"first row of {csv_path.name} has empty devanagari and iast"
                )
            if tr_h in first and not (first.get(tr_h) or "").strip():
                print(
                    f"WARN: first row translation empty in {csv_path.name}",
                    file=sys.stderr,
                )

    print(
        f"OK: {root} — {len(levels)} level(s), {total_rows} row(s), "
        f"course_id={manifest['course_id']!r}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
