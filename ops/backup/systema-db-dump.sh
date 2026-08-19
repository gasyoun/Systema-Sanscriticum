#!/usr/bin/env bash
set -euo pipefail

DEST_DIR=/var/backups/systema/db
DEST=$DEST_DIR/laravel.sql
TMP=$DEST.tmp

mkdir -p "$DEST_DIR"
chmod 0700 "$DEST_DIR"

if mariadb-dump --single-transaction --routines --triggers --events laravel > "$TMP"; then
    mv "$TMP" "$DEST"
    echo "systema-db-dump: OK $(date -u +%Y-%m-%dT%H:%M:%SZ) $(stat -c%s "$DEST") bytes"
else
    rc=$?
    rm -f "$TMP"
    echo "systema-db-dump: FAIL $(date -u +%Y-%m-%dT%H:%M:%SZ) exit=$rc — previous dump left in place" >&2
    exit "$rc"
fi
