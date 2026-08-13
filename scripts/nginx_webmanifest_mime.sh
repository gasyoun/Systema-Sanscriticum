#!/bin/bash
# Serve /manifest.webmanifest as application/manifest+json instead of
# application/octet-stream on samskrte.ru.
#
# Why a scoped `location` and not /etc/nginx/mime.types:
#   - mime.types is a dpkg conffile owned by nginx-common, so an edit there argues
#     with the package manager on every upgrade and can be silently reverted.
#   - A `types { ... }` block inside the server would REPLACE the inherited type
#     table rather than extend it (nginx has no merge semantics for `types`), and
#     you cannot have two `types` blocks in one context — so that route risks
#     breaking every other MIME type on the site to fix one.
#   - An exact-match `location =` has the highest routing priority in nginx and
#     carries only `default_type`, which applies solely when the extension yields
#     no type. It cannot disturb any other file's MIME resolution. `root` is
#     inherited from the server block, so the file is still served normally.
#
# Idempotent: re-running detects the marker and makes no second edit.
set -euo pipefail

CONF=/etc/nginx/sites-available/samskrte.ru
[ -f "$CONF" ] || CONF=/etc/nginx/sites-enabled/samskrte.ru
MARKER='# webmanifest MIME (H2634 follow-up)'

if grep -qF "$MARKER" "$CONF"; then
    echo "already present in $CONF — nothing to do"
    exit 0
fi

BACKUP="$CONF.bak-webmanifest-mime-$(date -u +%Y%m%dT%H%M%SZ)"
cp -a "$CONF" "$BACKUP"
echo "backup: $BACKUP"

# Insert immediately before the `location / {` line of the first server block.
python3 - "$CONF" "$MARKER" <<'PY'
import io
import sys

conf, marker = sys.argv[1], sys.argv[2]
lines = io.open(conf, encoding='utf-8').read().split('\n')

idx = next(i for i, l in enumerate(lines) if l.strip().startswith('location / {'))
indent = ' ' * (len(lines[idx]) - len(lines[idx].lstrip()))

block = [
    f'{indent}{marker} — nginx mime.types has no .webmanifest, so it fell back to',
    f'{indent}# the http-level `default_type application/octet-stream`. Exact-match',
    f'{indent}# location: highest priority, touches no other file\'s type resolution.',
    f'{indent}location = /manifest.webmanifest {{',
    f'{indent}    default_type application/manifest+json;',
    f'{indent}}}',
    '',
]

lines[idx:idx] = block
io.open(conf, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines))
print(f'inserted at line {idx + 1} of {conf}')
PY

echo '--- nginx -t ---'
if ! nginx -t; then
    echo 'CONFIG TEST FAILED — restoring backup, nothing reloaded' >&2
    cp -a "$BACKUP" "$CONF"
    nginx -t
    exit 1
fi

systemctl reload nginx
echo 'nginx reloaded'
