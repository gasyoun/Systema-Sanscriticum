#!/usr/bin/env python3
"""H1146 (W1-D5) — validate a Memrise export directory against the
manifest.json contract documented in
database/seeders/data/memrise_6679375/README.md, WITHOUT any network access
or credentials. Run this immediately after a human's export pull, and in CI
against tests/fixtures/memrise_sample/.

Usage:
    python scripts/memrise_export_validate.py <directory>

Exit code 0 = valid; non-zero = invalid, with the reason printed to stderr.

Checks:
  - manifest.json exists and parses as JSON
  - manifest.json has the required keys (course_id, course_name, columns, levels)
  - every levels[].file exists in <directory>
  - each CSV's header row contains every column name declared in `columns`
  - no level's CSV is empty (header only, zero data rows)
"""
from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

REQUIRED_MANIFEST_KEYS = ("course_id", "course_name", "columns", "levels")


class ValidationError(Exception):
    pass


def load_manifest(directory: Path) -> dict:
    manifest_path = directory / "manifest.json"
    if not manifest_path.is_file():
        raise ValidationError(f"manifest.json not found in {directory}")

    try:
        raw = manifest_path.read_text(encoding="utf-8")
    except OSError as exc:
        raise ValidationError(f"cannot read {manifest_path}: {exc}") from exc

    try:
        manifest = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValidationError(f"manifest.json is not valid JSON: {exc}") from exc

    if not isinstance(manifest, dict):
        raise ValidationError("manifest.json must be a JSON object")

    for key in REQUIRED_MANIFEST_KEYS:
        if key not in manifest:
            raise ValidationError(f"manifest.json missing required key: {key}")

    if not isinstance(manifest["columns"], dict) or not manifest["columns"]:
        raise ValidationError("manifest.json 'columns' must be a non-empty object")

    if not isinstance(manifest["levels"], list) or not manifest["levels"]:
        raise ValidationError("manifest.json 'levels' must be a non-empty list")

    return manifest


def validate_level(directory: Path, level: dict, declared_headers: list) -> None:
    if "file" not in level:
        raise ValidationError(f"level entry missing 'file': {level!r}")

    csv_path = directory / level["file"]
    if not csv_path.is_file():
        raise ValidationError(f"level file declared in manifest not found: {csv_path}")

    with open(csv_path, "r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle)
        try:
            header_row = next(reader)
        except StopIteration:
            raise ValidationError(f"{csv_path} is empty (no header row)") from None

        missing = [h for h in declared_headers if h not in header_row]
        if missing:
            raise ValidationError(
                f"{csv_path} header {header_row!r} is missing manifest-declared "
                f"column(s): {missing!r}"
            )

        try:
            next(reader)
        except StopIteration:
            raise ValidationError(f"{csv_path} has a header row but zero data rows") from None


def validate(directory: Path) -> None:
    manifest = load_manifest(directory)
    declared_headers = list(manifest["columns"].values())

    for level in manifest["levels"]:
        validate_level(directory, level, declared_headers)


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

    if len(sys.argv) != 2:
        print(f"usage: {sys.argv[0]} <directory>", file=sys.stderr)
        return 2

    directory = Path(sys.argv[1])
    if not directory.is_dir():
        print(f"error: not a directory: {directory}", file=sys.stderr)
        return 2

    try:
        validate(directory)
    except ValidationError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1

    print(f"OK: {directory} matches the manifest contract")
    return 0


if __name__ == "__main__":
    sys.exit(main())
