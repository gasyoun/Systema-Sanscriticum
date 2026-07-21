#!/usr/bin/env python3
"""H1146 (W1-D5) / H1431 — pull a Memrise community course export into the
manifest.json + level_NN.csv shape that `php artisan srs:import-memrise`
already reads (see database/seeders/data/memrise_6679375/README.md).

Usage:
    MEMRISE_SESSION=<cookie value>  python scripts/memrise_export.py \
        --course 6679375 --out database/seeders/data/memrise_6679375

    python scripts/memrise_export.py --course 6679375 --out ... --dry-run

The session credential is read ONLY from the MEMRISE_SESSION environment
variable, never from argv (argv leaks into shell history / process lists).
It is the value of the `sessionid_2` cookie set by community-courses.memrise.com
after login (found empirically 21-07-2026 — earlier drafts of this script
guessed a `session` cookie name and a `/community/api/course/{id}/` JSON
endpoint; neither exists live, see H1431).
--dry-run prints the discovered level/column inventory and writes nothing.

Verified against live Memrise 21-07-2026 (H1431, Sonnet 5 `claude-sonnet-5`)
against course 6502608. Memrise serves no course JSON API for community
courses — this scrapes the server-rendered HTML instead: the course root
redirects (301) to its slugged URL and lists level links
(`/community/course/{id}/{slug}/{level}/`); each level page lists word rows
as elements carrying `data-thing-id`, with per-column text in
`.col_a .text`, `.col_b .text`, etc. (column count varies by course).
"""
from __future__ import annotations

import argparse
import csv
import html
import json
import os
import re
import sys
import urllib.request
from datetime import date, timezone
from pathlib import Path

MEMRISE_SESSION_ENV = "MEMRISE_SESSION"
MEMRISE_SESSION_COOKIE = "sessionid_2"
USER_AGENT = "Mozilla/5.0 (compatible; SystemaMemriseExporter/1.0)"

LEVEL_LINK_RE = re.compile(
    r'href="(/community/course/(\d+)/([a-z0-9-]+)/(\d+)/)"\s+class="level clearfix"',
)
THING_ROW_RE = re.compile(r'data-thing-id="(\d+)"')
COL_RE = re.compile(r'class="(col_[a-z]) col text"[^>]*>\s*<div class="text">(.*?)</div>', re.DOTALL)
COURSE_NAME_RE = re.compile(r'<h1 class="course-name[^"]*"[^>]*>(.*?)</h1>', re.DOTALL)
TAG_RE = re.compile(r'<[^>]+>')


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


def fetch_html(url: str, session: str) -> str:
    request = urllib.request.Request(
        url,
        headers={
            "Cookie": f"{MEMRISE_SESSION_COOKIE}={session}",
            "User-Agent": USER_AGENT,
        },
    )
    # Semgrep's dynamic-urllib-use rule flags any f-string reaching urlopen, but the
    # scheme and host here are always a hardcoded literal
    # ("https://community-courses.memrise.com/..."); course_id/slug/level only ever
    # land in the path segment, so they cannot redirect the request to a different
    # host or scheme (e.g. file://) regardless of their value.
    with urllib.request.urlopen(request) as response:  # noqa: S310 - fixed https host  # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        return response.read().decode("utf-8")


def parse_level_rows(level_html: str) -> list[dict[str, str]]:
    """Parse one level page into a list of {column_key: text} row dicts.

    Column keys are whatever `col_a`/`col_b`/... classes actually appear —
    course authors choose how many columns a course has (2 for a simple
    word/translation course, more for word/IAST/translation/notes etc.). A
    level can legitimately have zero rows (an empty/placeholder level).
    """
    rows: list[dict[str, str]] = []
    for thing_match in THING_ROW_RE.finditer(level_html):
        row_start = thing_match.end()
        row_end = level_html.find('data-thing-id="', row_start)
        row_html = level_html[row_start: row_end if row_end != -1 else row_start + 4000]
        cells = {col: html.unescape(text).strip() for col, text in COL_RE.findall(row_html)}
        if cells:
            rows.append(cells)
    return rows


def fetch_course_schema(course_id: str, session: str) -> dict:
    """Discover a community course's levels + word rows by scraping its
    server-rendered HTML (Memrise serves no JSON API for community courses —
    see the module docstring). Verified live against course 6502608,
    21-07-2026 (H1431).
    """
    root_url = f"https://community-courses.memrise.com/community/course/{course_id}/"
    root_html = fetch_html(root_url, session)

    name_match = COURSE_NAME_RE.search(root_html)
    course_name = html.unescape(TAG_RE.sub("", name_match.group(1))).strip() if name_match else ""

    level_links: dict[int, tuple[str, str]] = {}
    for href, cid, slug, level_index in LEVEL_LINK_RE.findall(root_html):
        level_links[int(level_index)] = (slug, href)
    if not level_links:
        raise RuntimeError(
            "no level links found on the course page — Memrise's HTML shape "
            "may have changed since 21-07-2026 (H1431); re-inspect the fetched "
            "page and update LEVEL_LINK_RE / COL_RE accordingly"
        )

    levels = []
    for index in sorted(level_links):
        slug, href = level_links[index]
        level_url = f"https://community-courses.memrise.com{href}"
        level_html = fetch_html(level_url, session)
        row_dicts = parse_level_rows(level_html)
        levels.append({
            "index": index,
            "name": f"Level {index}",
            "file": f"level_{index:02d}.csv",
            "row_dicts": row_dicts,
        })

    # Union of columns across all levels, in first-seen order — some courses
    # vary column count level to level (e.g. an empty placeholder level, or a
    # "notes" column added later). Every level's CSV gets this same header,
    # padded with "" for columns that level didn't have, so the manifest
    # contract holds even for a zero-row level.
    all_columns: list[str] = []
    for level in levels:
        for row in level["row_dicts"]:
            for col in row:
                if col not in all_columns:
                    all_columns.append(col)

    for level in levels:
        level["columns"] = all_columns
        level["rows"] = [
            [row.get(col, "") for col in all_columns] for row in level["row_dicts"]
        ]
        del level["row_dicts"]

    return {
        "course_name": course_name,
        "source_url": root_url,
        "columns": {col: col for col in all_columns},
        "levels": levels,
    }


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
            "Live scraping failed — Memrise's HTML shape may have changed since "
            "21-07-2026 (H1431). Fall back to CourseDump2022 if so.",
            file=sys.stderr,
        )
        return 1

    all_levels = schema.get("levels", [])
    levels = [lvl for lvl in all_levels if lvl.get("rows")]
    skipped = [lvl for lvl in all_levels if not lvl.get("rows")]
    columns = schema.get("columns", {})

    print(f"  discovered {len(all_levels)} level(s), {len(levels)} non-empty")
    for level in levels:
        print(f"    level {level.get('index')}: {level.get('name')} — file {level.get('file')} "
              f"({len(level.get('rows', []))} rows)")
    if skipped:
        print(f"  skipped {len(skipped)} empty level(s): "
              f"{', '.join(str(lvl['index']) for lvl in skipped)} (no card rows — "
              "the manifest contract forbids header-only CSVs)")
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
        levels=[
            {"index": lvl["index"], "name": lvl["name"], "file": lvl["file"], "columns": lvl["columns"]}
            for lvl in levels
        ],
    )

    for level in levels:
        write_level_csv(
            out_dir,
            level["file"],
            header=level["columns"],
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
