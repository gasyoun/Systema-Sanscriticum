"""Keep the Keep-a-Changelog comparison-link table in CHANGELOG.md complete.

The table at the bottom of CHANGELOG.md maps every `## [x.y.z]` section to a
GitHub compare URL. Nothing verified it, so it silently stopped being maintained
after v1.12.0 and 39 releases shipped without a link; this script rebuilds it
from the section headings and the real git tags.

  python scripts/changelog_compare_links.py            # check (exit 1 if stale)
  python scripts/changelog_compare_links.py --apply     # rewrite the table

A link is only emitted when BOTH endpoints exist as real git tags. The early
changelog history was reconstructed from git in the 2026-07-12 backfill and could
name a version that was never tagged; a compare URL to a missing tag 404s, so
such versions are skipped and reported rather than linked. Run with tags fetched
(`git fetch --tags`), otherwise everything looks untagged.

/cut-release adds the link for the version it cuts, so in normal operation this
script should always report "in sync".
"""

import os
import re
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

OWNER_REPO = 'gasyoun/Systema-Sanscriticum'
COMPARE = 'https://github.com/' + OWNER_REPO + '/compare/v{prev}...v{cur}'
FIRST = 'https://github.com/' + OWNER_REPO + '/releases/tag/v{cur}'

SECTION_RE = re.compile(r'^## \[(\d+\.\d+\.\d+)\]')
LINK_RE = re.compile(r'^\[(\d+\.\d+\.\d+)\]:')


def version_key(v):
    return tuple(int(p) for p in v.split('.'))


def git_tags(repo):
    out = subprocess.run(
        ['git', '-C', repo, 'tag', '-l'],
        capture_output=True, text=True, encoding='utf-8', check=True,
    )
    return set(t.strip()[1:] for t in out.stdout.split('\n') if t.strip().startswith('v'))


def main():
    repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    apply_changes = '--apply' in sys.argv
    path = os.path.join(repo, 'CHANGELOG.md')

    with open(path, encoding='utf-8') as fh:
        lines = fh.read().split('\n')

    tags = git_tags(repo)

    doc_versions = []
    for line in lines:
        m = SECTION_RE.match(line)
        if m and m.group(1) not in doc_versions:
            doc_versions.append(m.group(1))

    existing = {}
    link_idx = []
    for i, line in enumerate(lines):
        m = LINK_RE.match(line)
        if m:
            existing[m.group(1)] = line
            link_idx.append(i)
        elif line.startswith('[Unreleased]:'):
            link_idx.append(i)

    if not link_idx:
        print('no link table found in CHANGELOG.md')
        return 1

    ordered = sorted(doc_versions, key=version_key, reverse=True)
    tagged = [v for v in ordered if v in tags]
    untagged = [v for v in ordered if v not in tags]

    rebuilt = []
    for pos, cur in enumerate(tagged):
        if pos + 1 < len(tagged):
            rebuilt.append('[{0}]: {1}'.format(cur, COMPARE.format(prev=tagged[pos + 1], cur=cur)))
        else:
            rebuilt.append('[{0}]: {1}'.format(cur, FIRST.format(cur=cur)))

    added = [ln for ln in rebuilt if LINK_RE.match(ln).group(1) not in existing]
    changed = [ln for ln in rebuilt
               if LINK_RE.match(ln).group(1) in existing
               and existing[LINK_RE.match(ln).group(1)] != ln]

    print('sections {0} · tagged {1} · existing links {2} · to add {3} · to change {4}'.format(
        len(doc_versions), len(tagged), len(existing), len(added), len(changed)))
    if untagged:
        print('untagged, skipped: {0}'.format(', '.join(untagged)))
    for ln in changed:
        v = LINK_RE.match(ln).group(1)
        print('  was: {0}'.format(existing[v]))
        print('  now: {0}'.format(ln))

    # A link whose version has no tag stays as authored — deleting it would lose
    # information this script cannot regenerate.
    stale = sorted(set(existing) - set(tagged), key=version_key, reverse=True)
    if stale:
        print('kept as-is (version has no tag): {0}'.format(', '.join(stale)))
        rebuilt.extend(existing[v] for v in stale)

    if not (added or changed):
        print('in sync')
        return 0

    if not apply_changes:
        print('STALE — run with --apply')
        return 1

    start, end = min(link_idx), max(link_idx)
    unreleased = [ln for ln in lines[start:end + 1] if ln.startswith('[Unreleased]:')]
    lines[start:end + 1] = unreleased + rebuilt

    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(lines))

    print('wrote {0} link lines'.format(len(unreleased) + len(rebuilt)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
