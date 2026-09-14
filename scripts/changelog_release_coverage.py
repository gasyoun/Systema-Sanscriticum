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

`--since` defaults to CLEAN_SINCE, the oldest version whose coverage is known-clean.
As of 03-09-2026 that is v1.0.0: the backfill closed the whole history, so the
default window is every version in the file and there is no historic backlog to
except. Should a gap ever be accepted rather than repaired, raise CLEAN_SINCE past
it deliberately and say why here.

Releases are matched on the **tag**, never on the display name. Release names here
routinely differ from their tag ("1.90.32" without the v, "v1.90.22 — аптайм волна 4
подготовлена..." with a title appended), and an audit keyed on the name reports a
backlog twenty times larger than the real one.

Releases are read via `gh`, excluding drafts — a draft is invisible to anyone
without write access, so for a reader it is simply absent. Where `gh` is missing
or unauthenticated the report says so and checks tags only, rather than claiming a
false all-clear; under `--check` that same condition is a hard failure (exit 3),
because a gate that could not read half its subject has not gated anything.

Exit codes: 0 clean · 1 a version is missing a tag or a release · 2 no tags visible
at all (a checkout problem) · 3 releases unreadable under --check.
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
CLEAN_SINCE = "1.0.0"


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


def has_any_tags() -> bool:
    """False in a checkout that fetched no tags at all.

    `actions/checkout` defaults to `fetch-depth: 1`, which fetches no tags.
    Without this guard the gate would read zero tags, report every version as
    untagged, and fail with a 279-line list that says nothing about the real
    cause. A repo with a CHANGELOG but not one single `v*` tag is a checkout
    problem, never a coverage problem.
    """
    _, out = run(["git", "tag", "-l"])
    return bool(out.strip())


def published_releases() -> set[str] | None:
    """Published release tag names, or None when gh cannot answer.

    `--exclude-drafts` is not a nicety. A draft release is invisible to
    everyone without write access, so for any reader following a link it is
    simply absent — and whether `gh release list` shows it depends on the
    caller's token, which made this check answer differently for a maintainer
    and for CI. v1.90.20 was a draft for eight days: a local run with a write
    token called it published, CI's `contents: read` could not see it and
    called it missing. CI was right. Excluding drafts explicitly makes the
    verdict a property of the repo rather than of whoever asked.
    """
    if not shutil.which("gh"):
        return None
    # gh exits non-zero on a transient TLS/DNS blip as readily as on a real
    # auth failure, and both looked identical here: the releases half went
    # UNKNOWN twice on a flaky network during the pass that wrote this.
    # Retry before concluding gh cannot answer.
    for attempt in range(3):
        code, out = run(["gh", "release", "list", "--limit", "500",
                         "--exclude-drafts"])
        if code == 0:
            break
        if attempt == 2:
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

    if not has_any_tags():
        print("No git tags are visible in this checkout at all, so tag coverage")
        print("cannot be judged. This is a checkout problem, not a coverage gap:")
        print("`actions/checkout` fetches no tags at its default `fetch-depth: 1`.")
        print("Set `fetch-depth: 0` on the checkout step, or fetch the tag refs.")
        return 2

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

    if args.check and releases is None:
        print()
        print("Refusing to pass: --check could not read the releases at all, so")
        print("half of this gate never ran. A green light that checked nothing is")
        print("worse than a red one. Report mode (no --check) still degrades to a")
        print("tags-only answer; a gate does not get to.")
        return 3

    if args.check and (no_tag or no_release):
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
