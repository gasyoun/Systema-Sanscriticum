#!/usr/bin/env python3
"""H1146 (W1-D5) — pull a Memrise community course export into the
manifest.json + level_NN.csv shape that `php artisan srs:import-memrise`
already reads (see database/seeders/data/memrise_6679375/README.md).

Usage:
    MEMRISE_SESSION=<cookie value>  python scripts/memrise_export.py \
        --course 6679375 --out database/seeders/data/memrise_6679375

    python scripts/memrise_export.py --course 6679375 --out ... --dry-run

The session credential is read ONLY from the MEMRISE_SESSION environment
variable, never from argv (argv leaks into shell history / process lists).
--dry-run prints the discovered level/column inventory and writes nothing.

Untested against live Memrise (no agent-held credentials) — see
database/seeders/data/memrise_6679375/README.md and
docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md §6.3 for the honest
boundary. Validate any real export with memrise_export_validate.py before
running the importer.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import sys
import urllib.request
from datetime import date, timezone
from pathlib import Path

MEMRISE_SESSION_ENV = "MEMRISE_SESSION"


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Export a Memrise community course into the manifest.json + "
                     "level_NN.csv contract read by `php artisan srs:import-memrise`. "
                     f"Requires the {MEMRISE_SESSION_ENV} environment variable "
                     "(a Memrise session cookie) — never pass it as an argument.",
    )
    parser.add_argument("--course", required=True, help="Memrise course id, e.g. 6679375")
    parser.add_argument("--out", required=True, help="Destination directory for manifest.json + level CSVs")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print the discovered level/column inventory and write nothing",
    )
    return parser


def require_session() -> str:
    session = os.environ.get(MEMRISE_SESSION_ENV)
    if not session:
        print(
            f"error: {MEMRISE_SESSION_ENV} is not set. Export a Memrise session "
            "cookie into that environment variable first (never pass it as an "
            "argument — it would leak into shell history).",
            file=sys.stderr,
        )
        sys.exit(1)
    return session


def fetch_course_schema(course_id: str, session: str) -> dict:
    """Fetch the course's level/column structure from Memrise.

    NOT exercised against live Memrise by this agent session (no credential
    access) — see the README's stated blocker and ARCHITECTURE §6.3. The
    request shape below follows Memrise's documented community-course API;
    it may need adjustment on first human run if the live response differs.
    """
    url = f"https://community-courses.memrise.com/community/api/course/{course_id}/"
    request = urllib.request.Request(url, headers={"Cookie": f"session={session}"})
    # Semgrep's dynamic-urllib-use rule flags any f-string reaching urlopen, but the
    # scheme and host here are a hardcoded literal ("https://community-courses.memrise.com/...");
    # course_id only ever lands in the path segment, so it cannot redirect the request to a
    # different host or scheme (e.g. file://) regardless of its value.
    with urllib.request.urlopen(request) as response:  # noqa: S310 - fixed https host  # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        return json.loads(response.read().decode("utf-8"))


def write_manifest(out_dir: Path, course_id: str, course_name: str, source_url: str,
                    columns: dict, levels: list) -> None:
    manifest = {
        "course_id": course_id,
        "course_name": course_name,
        "source_url": source_url,
        "language": "sa",
        "exported_at": date.today().isoformat(),
        "columns": columns,
        "levels": levels,
    }
    manifest_path = out_dir / "manifest.json"
    with open(manifest_path, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(manifest, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def write_level_csv(out_dir: Path, filename: str, header: list, rows: list) -> None:
    csv_path = out_dir / filename
    with open(csv_path, "w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(header)
        writer.writerows(rows)


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

    parser = build_arg_parser()
    args = parser.parse_args()

    session = require_session()
    out_dir = Path(args.out)

    print(f"Course {args.course} — fetching schema...")
    try:
        schema = fetch_course_schema(args.course, session)
    except Exception as exc:  # noqa: BLE001 - report and exit, don't half-write output
        print(f"error: could not fetch course {args.course}: {exc}", file=sys.stderr)
        print(
            "This runner is untested against live Memrise (see README + "
            "ARCHITECTURE §6.3). Fall back to CourseDump2022 if the API shape "
            "has changed.",
            file=sys.stderr,
        )
        return 1

    levels = schema.get("levels", [])
    columns = schema.get("columns", {})

    print(f"  discovered {len(levels)} level(s)")
    for level in levels:
        print(f"    level {level.get('index')}: {level.get('name')} — file {level.get('file')}")
    print(f"  discovered columns: {', '.join(columns.keys()) or '(none)'}")

    if args.dry_run:
        print("[dry-run] no files written")
        return 0

    out_dir.mkdir(parents=True, exist_ok=True)

    write_manifest(
        out_dir,
        course_id=args.course,
        course_name=schema.get("course_name", ""),
        source_url=schema.get("source_url", ""),
        columns=columns,
        levels=[{"index": lvl["index"], "name": lvl["name"], "file": lvl["file"]} for lvl in levels],
    )

    for level in levels:
        write_level_csv(
            out_dir,
            level["file"],
            header=list(columns.values()),
            rows=level.get("rows", []),
        )

    # Keep media (audio/images) — re-fetching after Memrise's community-course
    # sunset may be impossible; audio processing itself is deferred (D4).
    media = schema.get("media", [])
    if media:
        print(f"  {len(media)} media file(s) referenced by the schema — "
              "media download is not yet implemented in this runner; "
              "fetch and place alongside the CSVs manually if needed.")

    print(f"Wrote manifest.json + {len(levels)} level CSV(s) to {out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
