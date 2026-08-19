#!/usr/bin/env bash
set -uo pipefail

LOG=/var/log/restic-backup.log
export RESTIC_PASSWORD_FILE=/root/.restic-pass
export RESTIC_REPOSITORY=sftp:restic-push@192.168.200.91:/systema
RESTIC_ARGS=(-o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp")

DUMP_SCRIPT=/usr/local/sbin/systema-db-dump.sh
SAMUDRA_DB_DIR=/opt/samudra/db
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

overall_rc=0

# 1. dump the Systema (Laravel) DB
if "$DUMP_SCRIPT" >>"$LOG" 2>&1; then
    dump_status=OK
else
    dump_status=FAIL
    overall_rc=1
fi

# 2. systema lane: restic backup of the DB dump + storage/app + .env + nginx
if restic "${RESTIC_ARGS[@]}" backup --tag systema \
    /var/backups/systema/db \
    /var/www/html/storage/app \
    /var/www/html/.env \
    /etc/nginx \
    --exclude 'storage/app/Laravel/*' \
    --exclude '*.log' \
    --exclude '*/cache/*' \
    >>"$LOG" 2>&1; then
    systema_status=OK
else
    systema_status=FAIL
    overall_rc=1
fi
echo "$(ts) lane=systema dump=${dump_status} backup=${systema_status}" >>"$LOG"

# 3. samudra lane: SamudraManthanam DB is SQLite (file-covered) per S0.6 DEFAULT — no separate dump.
if restic "${RESTIC_ARGS[@]}" backup --tag samudra \
    "$SAMUDRA_DB_DIR" \
    /opt/samudra/corpus \
    --exclude '*.log' \
    >>"$LOG" 2>&1; then
    samudra_status=OK
else
    samudra_status=FAIL
    overall_rc=1
fi
echo "$(ts) lane=samudra backup=${samudra_status}" >>"$LOG"

echo "$(ts) run_complete overall_rc=${overall_rc} systema=${systema_status} samudra=${samudra_status}" >>"$LOG"
exit "$overall_rc"
