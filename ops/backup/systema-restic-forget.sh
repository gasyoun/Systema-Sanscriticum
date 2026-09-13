#!/usr/bin/env bash
# Daily retention for the append-only restic repo (moved here from .92 on
# 13-09-2026: the SFTP push path is now append-only, so forget --prune can
# only run on the .91 side against the local path).
#
# Runs restic AS restic-push (uid 999) — the repo's owner — so the prune never
# leaves root:root 0400 files that the SFTP reader cannot open (§537
# root-poisoning class). The hourly restic-repo-ownership-sweep.timer is the
# belt; this is the braces.
set -uo pipefail
LOG=/var/log/restic-backup.log
ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }

run_as_push() {
    su -s /bin/bash restic-push -c '
        export HOME=/home/restic-push
        export RESTIC_PASSWORD_FILE=/home/restic-push/.restic-pass
        export RESTIC_REPOSITORY=/srv/restic/systema
        restic unlock --remove-all || true
        restic forget --retry-lock 30m --keep-tag untrusted-pre-cleanup \
            --keep-hourly 12 --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune
    '
}

if out=$(run_as_push 2>&1); then
    printf '%s\n' "$out" >>"$LOG"
    echo "$(ts) lane=forget-host91 status=OK" >>"$LOG"
    exit 0
else
    rc=$?
    printf '%s\n' "$out" >>"$LOG"
    echo "$(ts) lane=forget-host91 status=FAIL exit=$rc" >>"$LOG"
    exit "$rc"
fi
