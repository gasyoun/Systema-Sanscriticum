#!/usr/bin/env python3
"""Compare CHANGELOG version headings against git tags and GitHub releases.

A CHANGELOG heading is written by the release commit, so a session that stops after
the commit leaves a version that reads as shipped in every document that matters and
exists nowhere in `git tag` or the releases page. Nothing read back the other
direction until this script: H3692's own v1.90.35 sat untagged for four days while
CHANGELOG.md and RESULTS_LOG.md both linked to its 404 release page.

    python scripts/changelog_release_coverage.py              # report from --since
    python scripts/changelog_release_coverage.py --check      # exit 1 on any gap
    python scripts/changelog_release_coverage.py --since 1.0.0  # full history

`--since` defaults to the oldest version whose coverage is known-clean (see
CLEAN_SINCE). Everything older is documented historic backlog, not a regression: as
of 03-09-2026 exactly six versions below the floor still have no release
(v1.90.30, v1.90.29, v1.90.25, v1.90.21, v1.90.19, v1.0.0). Widening the window is a
deliberate act, not the gate's default.

Releases are matched on the **tag**, never on the display name. Release names here
routinely differ from their tag ("1.90.32" without the v, "v1.90.22 — аптайм волна 4
подготовлена..." with a title appended), and an audit keyed on the name reports a
backlog twenty times larger than the real one.

Releases are read via `gh`; where `gh` is missing or unauthenticated the script says
so and checks tags only, rather than reporting a false all-clear.
"""
from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

REPO = Path(__file__).resolve().parent.parent
CLEAN_SINCE = "1.90.34"


def version_key(v: str) -> tuple[int, ...]:
    return tuple(int(p) for p in v.split("."))


def run(cmd: list[str]) -> tuple[int, str]:
    proc = subprocess.run(
        cmd, cwd=REPO, capture_output=True, text=True,
        encoding="utf-8", errors="replace",
    )
    return proc.returncode, proc.stdout


def changelog_versions() -> list[str]:
    text = (REPO / "CHANGELOG.md").read_text(encoding="utf-8")
    return list(dict.fromkeys(re.findall(r"^## \[(\d+\.\d+\.\d+)\]", text, re.M)))


def local_tags() -> set[str]:
    _, out = run(["git", "tag", "-l", "v*"])
    return set(out.split())


def published_releases() -> set[str] | None:
    """Release tag names, or None when gh cannot answer."""
    if not shutil.which("gh"):
        return None
    code, out = run(["gh", "release", "list", "--limit", "500"])
    if code != 0:
        return None
    tags = set()
    for line in out.splitlines():
        cells = line.split("\t")
        if len(cells) >= 3:
            tags.add(cells[2].strip())
    return tags


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--since", default=CLEAN_SINCE,
                    help=f"oldest version to check (default {CLEAN_SINCE})")
    ap.add_argument("--check", action="store_true",
                    help="exit 1 when a version in the window lacks a tag or release")
    args = ap.parse_args()

    floor = version_key(args.since)
    versions = [v for v in changelog_versions() if version_key(v) >= floor]
    if not versions:
        print(f"No CHANGELOG versions at or above {args.since}.")
        return 0

    tags = local_tags()
    releases = published_releases()

    no_tag = [f"v{v}" for v in versions if f"v{v}" not in tags]
    no_release = ([f"v{v}" for v in versions if f"v{v}" not in releases]
                  if releases is not None else [])

    print(f"CHANGELOG versions from {args.since}: {len(versions)}")
    print(f"  missing git tag: {len(no_tag)}" + (f" — {' '.join(no_tag)}" if no_tag else ""))
    if releases is None:
        print("  missing release: UNKNOWN — gh unavailable or unauthenticated, tags only")
    else:
        print(f"  missing release: {len(no_release)}"
              + (f" — {' '.join(no_release)}" if no_release else ""))

    if no_tag or no_release:
        print()
        print("Repair, per version: tag the commit that introduced the version's own")
        print("CHANGELOG section (git log --reverse -S'## [<version>]' -- CHANGELOG.md),")
        print("push it as an explicit refs/tags/<tag> ref — a lightweight cut_release tag")
        print("is skipped by --follow-tags — then")
        print("  gh release create <tag> --verify-tag --notes-file <section> --latest=false")
        print("The --latest=false is not optional: a retro-published historic release")
        print("otherwise demotes the real newest one.")

    if args.check and (no_tag or no_release):
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
