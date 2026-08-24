"""Fail when the same changelog bullet appears in more than one place.

A changelog entry describes one change, so its bullet belongs to exactly one
`## [x.y.z]` section. Nothing verified that, and twice it stopped being true:

  * `a85acd81` (PR #684, 2026-07-24) inserted the H1620 "PWG Arzamas-style
    material plan" bullet **52 times** in a single commit — once per `### Added`
    heading in the whole file. Repaired by H1835 / PR #827.
  * `27ad957a` (PR #506, 2026-07-13) added the H881 optimisation-backlog bullet
    once, at `[Unreleased]`; a later merge left a second copy behind in `[1.4.0]`.
    Repaired in the same pass as this script.

Both were invisible to review: the duplicate lines are byte-identical to a
legitimate one, so no single section reads as wrong and a diff of the *repair*
is what finally makes them visible. This script is the missing verifier.

  python scripts/changelog_duplicate_bullets.py           # check (exit 1 if dupes)
  python scripts/changelog_duplicate_bullets.py --json    # machine-readable

There is deliberately no `--fix`. Deciding which copy survives is not mechanical:
it is the section whose git tag first contains the commit that introduced the
bullet (`git log -S"<text>"` then `git tag --contains <sha> --sort=creatordate`),
and a script that guessed would quietly delete the real entry and keep a stray.
The report prints that recipe for each duplicate instead.

Scope: top-level bullets (`- ` at column 0) inside `##` sections. Indented
continuation lines and sub-bullets are part of their parent entry, not entries in
their own right, so they are not compared on their own.
"""

import json
import os
import re
import sys
from collections import defaultdict

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

SECTION_RE = re.compile(r'^## ')
BULLET_RE = re.compile(r'^- \S')


def find_duplicates(text):
    """Return [(bullet, [(section, lineno), ...]), ...] for bullets seen >1 time."""
    section = None
    seen = defaultdict(list)
    for lineno, line in enumerate(text.split('\n'), 1):
        if SECTION_RE.match(line):
            section = line.strip()
            continue
        if section and BULLET_RE.match(line):
            seen[line.rstrip()].append((section, lineno))
    return sorted(
        ((b, hits) for b, hits in seen.items() if len(hits) > 1),
        key=lambda item: -len(item[1]),
    )


def main():
    repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    # Файл встречается в обоих регистрах (case-rename changelog.md -> CHANGELOG.md
    # на case-insensitive Windows не меняет запись в старых клонах) — берём то,
    # что реально лежит на диске, иначе Linux-CI падает на FileNotFoundError.
    candidates = ['CHANGELOG.md', 'changelog.md']
    path = next(
        (os.path.join(repo, name) for name in candidates if os.path.isfile(os.path.join(repo, name))),
        os.path.join(repo, candidates[0]),
    )
    display_name = os.path.basename(path)
    as_json = '--json' in sys.argv

    with open(path, encoding='utf-8') as fh:
        text = fh.read()

    dupes = find_duplicates(text)

    if as_json:
        print(json.dumps(
            [{'bullet': b, 'count': len(h),
              'sections': sorted(set(s for s, _ in h)),
              'lines': [n for _, n in h]}
             for b, h in dupes],
            ensure_ascii=False, indent=2,
        ))
        return 1 if dupes else 0

    total = sum(len(h) for _, h in dupes)
    if not dupes:
        print(display_name + ': no duplicated bullets')
        return 0

    print(display_name + ': {0} bullet(s) duplicated, {1} copies total\n'.format(
        len(dupes), total))
    for bullet, hits in dupes:
        sections = sorted(set(s for s, _ in hits))
        print('{0}x across {1} section(s):'.format(len(hits), len(sections)))
        print('  {0}'.format(bullet[:120] + ('…' if len(bullet) > 120 else '')))
        for section, lineno in hits:
            print('    line {0:<6} {1}'.format(lineno, section))
        print()

    print('Each bullet belongs to exactly ONE section. To find which, take the')
    print('commit that introduced it and the earliest tag that contains it:')
    print('  git log -S"<distinctive words>" --oneline -- changelog.md')
    print('  git tag --contains <sha> --sort=creatordate | head -1')
    print('Keep the copy in that version\'s section; delete the others.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
