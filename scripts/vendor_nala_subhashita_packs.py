#!/usr/bin/env python3
"""Vendor the H2165 Nalopākhyāna + subhāṣita pack freeze from kosha into this repo.

Twin of scripts/vendor_cohort_start_chteniya_packs.py (H2110), pointed at the
SECOND freeze kosha produced for these two courses:
`data/nala_subhashita/MANIFEST.json` (H2165, built 2026-08-16).

Reads the pinned bytes straight out of kosha's `origin/main` (not its working
tree, which can be stale) and verifies every file against the freeze MANIFEST's
own sha256 + byte count BEFORE writing. A hash mismatch aborts — vendoring an
unverified pin is exactly the drift the freeze exists to prevent.

Unlike H2110's script this one DOES vendor `subhashita-beginner`, whose schema
is sayings[]/lines[].chunks[] rather than reading_pack_v1's
sentences[]/tokens[]. It is still vendored VERBATIM (byte-identical to the pin,
so the sha256 check keeps working); the shape difference is normalised at READ
time by App\\Support\\SubhashitaReadingPackAdapter, which maps it onto the one
existing reading_pack_v1 contract. No second schema is invented, and no
normalised copy is written to disk — a rewritten file could never be verified
against the freeze again.

    python scripts/vendor_nala_subhashita_packs.py [--check]

`--check` verifies the already-vendored copies and writes nothing.
"""
import argparse
import hashlib
import json
import os
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

KOSHA = r'C:\Users\user\Documents\GitHub\kosha'
KOSHA_REF = 'origin/main'
MANIFEST_IN_KOSHA = 'data/nala_subhashita/MANIFEST.json'

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEST_DIR = os.path.join(HERE, 'resources', 'data', 'nala_subhashita')

# slug -> destination filename. All four packs of the freeze: the three Nala
# reading packs (the Nalopākhyāna course, narrative order 3.50 -> 3.51 -> 3.52)
# and the single subhāṣita pack (the Subhāṣita course).
VENDOR = {
    'nala-1': 'nala-1.json',
    'nala-2': 'nala-2.json',
    'nala-3': 'nala-3.json',
    'subhashita-beginner': 'subhashita_beginner_pack.json',
}


def git_show(path):
    """Bytes of `path` at KOSHA_REF. Binary-safe: no text decoding, no eol munging.

    Deliberately NOT `encoding='utf-8'` (the usual org rule for output-capturing
    subprocess calls): this function's whole job is to hand back the exact bytes
    the freeze hashed. Text mode would newline-translate on Windows and the
    sha256 check below would fail against every pin. stderr is decoded
    explicitly, which is where the encoding rule actually applies.
    """
    proc = subprocess.run(
        ['git', '-C', KOSHA, 'show', '%s:%s' % (KOSHA_REF, path)],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if proc.returncode:
        raise SystemExit('git show %s:%s failed: %s'
                         % (KOSHA_REF, path, proc.stderr.decode('utf-8', 'replace')))
    return proc.stdout


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--check', action='store_true',
                    help='verify the vendored copies against the freeze; write nothing')
    args = ap.parse_args()

    manifest_bytes = git_show(MANIFEST_IN_KOSHA)
    manifest = json.loads(manifest_bytes.decode('utf-8'))
    by_slug = {p['slug']: p for p in manifest['packs']}

    missing = sorted(set(VENDOR) - set(by_slug))
    if missing:
        raise SystemExit('freeze has no pack for slug(s): %s' % ', '.join(missing))

    os.makedirs(DEST_DIR, exist_ok=True)
    failures = []

    for slug, filename in sorted(VENDOR.items()):
        pack = by_slug[slug]
        payload = git_show(pack['pin_path'])
        digest = hashlib.sha256(payload).hexdigest()

        if digest != pack['sha256'] or len(payload) != pack['bytes']:
            failures.append(
                '%s: freeze says sha256=%s bytes=%d, pinned file is sha256=%s bytes=%d'
                % (slug, pack['sha256'], pack['bytes'], digest, len(payload)))
            continue

        dest = os.path.join(DEST_DIR, filename)
        if args.check:
            if not os.path.isfile(dest):
                failures.append('%s: not vendored yet (%s missing)' % (slug, dest))
                continue
            with open(dest, 'rb') as fh:
                have = fh.read()
            if hashlib.sha256(have).hexdigest() != pack['sha256']:
                failures.append('%s: vendored copy drifted from the freeze' % slug)
                continue
            print('ok    %-20s %s (%d bytes)' % (slug, pack['sha256'][:12], len(have)))
        else:
            with open(dest, 'wb') as fh:
                fh.write(payload)
            print('wrote %-20s %s (%d bytes) -> %s'
                  % (slug, digest[:12], len(payload),
                     os.path.relpath(dest, HERE).replace('\\', '/')))

    # The manifest itself is vendored verbatim so the provenance travels with the data.
    dest_manifest = os.path.join(DEST_DIR, 'MANIFEST.json')
    if args.check:
        if not os.path.isfile(dest_manifest):
            failures.append('MANIFEST.json: not vendored yet')
        else:
            with open(dest_manifest, 'rb') as fh:
                if fh.read() != manifest_bytes:
                    failures.append('MANIFEST.json: vendored copy differs from the freeze')
                else:
                    print('ok    %-20s (verbatim from kosha %s)' % ('MANIFEST.json', KOSHA_REF))
    else:
        with open(dest_manifest, 'wb') as fh:
            fh.write(manifest_bytes)
        print('wrote %-20s (%d bytes, verbatim from kosha %s)'
              % ('MANIFEST.json', len(manifest_bytes), KOSHA_REF))

    if failures:
        for line in failures:
            print('FAIL  %s' % line, file=sys.stderr)
        raise SystemExit(1)

    print('\nfreeze: %s (built %s, handoff %s)'
          % (manifest['id'], manifest['built'], manifest['handoff']))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
