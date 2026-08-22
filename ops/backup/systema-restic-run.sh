#!/usr/bin/env bash
set -uo pipefail

LOG=/var/log/restic-backup.log
export RESTIC_PASSWORD_FILE=/root/.restic-pass
export RESTIC_REPOSITORY=sftp:restic-push@192.168.200.91:/systema
RESTIC_ARGS=(-o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp")

DUMP_SCRIPT=/usr/local/sbin/systema-db-dump.sh
SAMUDRA_DB_DIR=/opt/samudra/db
S3_ENV_FILE=/root/.restic-s3.env
S3_ENDPOINT=https://storage.yandexcloud.net
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

overall_rc=0

# 1. dump the Systema (Laravel) DB
if "$DUMP_SCRIPT" >>"$LOG" 2>&1; then
    dump_status=OK
else
    dump_status=FAIL
    overall_rc=1
fi

# 2. systema lane: restic backup of the DB dump + storage/app + .env + nginx (SFTP leg)
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
echo "$(ts) lane=systema dest=sftp dump=${dump_status} backup=${systema_status}" >>"$LOG"

# 3. samudra lane: SamudraManthanam DB is SQLite (file-covered) per S0.6 DEFAULT — no separate dump. (SFTP leg)
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
echo "$(ts) lane=samudra dest=sftp backup=${samudra_status}" >>"$LOG"

# SFTP aggregate for the run summary (fast local copy must never wait on S3 — H3175 push order rule)
if [ "$systema_status" = OK ] && [ "$samudra_status" = OK ]; then
    sftp_agg=OK
else
    sftp_agg=FAIL
fi

# 4. S3 leg (H3175 wave 1): immutable offsite, ONLY when provisioned.
#    Contract: /root/.restic-s3.env (0600 root:root) defines
#      RESTIC_S3_BUCKET, RESTIC_AWS_ACCESS_KEY_ID, RESTIC_AWS_SECRET_ACCESS_KEY
#    Push order is deliberate: SFTP above already succeeded before any S3 traffic.
s3_status=SKIP
if [ -r "$S3_ENV_FILE" ] && [ -s "$S3_ENV_FILE" ]; then
    # shellcheck disable=SC1090
    if ! . "$S3_ENV_FILE"; then
        s3_status=MISCONFIGURED
        echo "$(ts) lane=s3 dest=s3 status=MISCONFIGURED reason=cannot-source-envfile" >>"$LOG"
        overall_rc=1
    elif [ -z "${RESTIC_S3_BUCKET:-}" ] || [ -z "${RESTIC_AWS_ACCESS_KEY_ID:-}" ] || [ -z "${RESTIC_AWS_SECRET_ACCESS_KEY:-}" ]; then
        s3_status=MISCONFIGURED
        echo "$(ts) lane=s3 dest=s3 status=MISCONFIGURED reason=missing-required-var" >>"$LOG"
        overall_rc=1
    else
        export AWS_ACCESS_KEY_ID="$RESTIC_AWS_ACCESS_KEY_ID"
        export AWS_SECRET_ACCESS_KEY="$RESTIC_AWS_SECRET_ACCESS_KEY"
        S3_REPO="s3:${S3_ENDPOINT}/${RESTIC_S3_BUCKET}/systema"

        if restic -r "$S3_REPO" backup --tag systema \
            /var/backups/systema/db \
            /var/www/html/storage/app \
            /var/www/html/.env \
            /etc/nginx \
            --exclude 'storage/app/Laravel/*' \
            --exclude '*.log' \
            --exclude '*/cache/*' \
            >>"$LOG" 2>&1; then
            s3_systema=OK
        else
            s3_systema=FAIL
            overall_rc=1
        fi
        echo "$(ts) lane=systema dest=s3 backup=${s3_systema}" >>"$LOG"

        if restic -r "$S3_REPO" backup --tag samudra \
            "$SAMUDRA_DB_DIR" \
            /opt/samudra/corpus \
            --exclude '*.log' \
            >>"$LOG" 2>&1; then
            s3_samudra=OK
        else
            s3_samudra=FAIL
            overall_rc=1
        fi
        echo "$(ts) lane=samudra dest=s3 backup=${s3_samudra}" >>"$LOG"

        if [ "$s3_systema" = OK ] && [ "$s3_samudra" = OK ]; then
            s3_status=OK
        else
            s3_status=FAIL
        fi
        unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
    fi
else
    # DEFAULT applied (plan stop condition "credential does not exist yet"):
    # record, skip this leg, continue — an unprovisioned destination is not a
    # failing run. Once the env file appears the next hourly tick pushes for real,
    # and from then on a failing S3 leg DOES fail the whole run (criterion 1.4).
    echo "$(ts) lane=s3 dest=s3 status=SKIP reason=no-${S3_ENV_FILE}" >>"$LOG"
fi

echo "$(ts) run_complete overall_rc=${overall_rc} systema=${systema_status} samudra=${samudra_status} sftp=${sftp_agg} s3=${s3_status}" >>"$LOG"
exit "$overall_rc"
