#!/usr/bin/env bash
set -uo pipefail

LOG=/var/log/restic-backup.log
export RESTIC_PASSWORD_FILE=/root/.restic-pass
export RESTIC_REPOSITORY=sftp:restic-push@192.168.200.91:/systema
RESTIC_ARGS=(-o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp")
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

if restic "${RESTIC_ARGS[@]}" forget --keep-hourly 24 --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune >>"$LOG" 2>&1; then
    echo "$(ts) lane=forget status=OK" >>"$LOG"
    exit 0
else
    rc=$?
    echo "$(ts) lane=forget status=FAIL exit=${rc}" >>"$LOG"
    exit "$rc"
fi
