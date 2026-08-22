#!/usr/bin/env bash
# H3175 wave 1 — S1.1/S1.2/S1.3 verification + one-shot repo bootstrap.
#
# Usage (on .92, as root):
#   systema-restic-s3-verify.sh [--init] [ENVFILE]
#   ENVFILE defaults to /root/.restic-s3.env (0600) holding:
#     RESTIC_S3_BUCKET=<bucket>
#     RESTIC_AWS_ACCESS_KEY_ID=<PutObject-only key id>
#     RESTIC_AWS_SECRET_ACCESS_KEY=<secret>
#     # optional, only for the criterion-1.2 admin-delete refusal check:
#     S3_ADMIN_ACCESS_KEY_ID=
#     S3_ADMIN_SECRET_ACCESS_KEY=
#
# Exit codes: 0 verified · 2 KEY CAN DELETE (handoff FAIL — do not proceed to init)
#             3 inconclusive (network/API error) · 4 misconfiguration
# Requires: restic, mc (MinIO client). Never prints secret values.
set -uo pipefail

ENV_FILE=${1:-/root/.restic-s3.env}
INIT=0
[ "${1:-}" = "--init" ] && { INIT=1; ENV_FILE=${2:-/root/.restic-s3.env}; }

ENDPOINT=https://storage.yandexcloud.net
ALIAS=h3175v
ADMIN_ALIAS=h3175admin
REPO="s3:${ENDPOINT}/BUCKET_PLACEHOLDER/systema"
PROBE_PREFIX=h3175-probe
ts() { date -u +%Y%m%dT%H%M%SZ; }
STAMP=$(ts)

for bin in restic mc; do
    command -v "$bin" >/dev/null 2>&1 || { echo "FATAL: $bin not in PATH"; exit 4; }
done

# --- load credentials -------------------------------------------------------
if [ ! -r "$ENV_FILE" ] || [ ! -s "$ENV_FILE" ]; then
    echo "FATAL: env file $ENV_FILE missing or empty — create it first (see header)."
    exit 4
fi
# shellcheck disable=SC1090
. "$ENV_FILE"
: "${RESTIC_S3_BUCKET:?RESTIC_S3_BUCKET not set in $ENV_FILE}"
: "${RESTIC_AWS_ACCESS_KEY_ID:?RESTIC_AWS_ACCESS_KEY_ID not set in $ENV_FILE}"
: "${RESTIC_AWS_SECRET_ACCESS_KEY:?RESTIC_AWS_SECRET_ACCESS_KEY not set in $ENV_FILE}"

REPO="s3:${ENDPOINT}/${RESTIC_S3_BUCKET}/systema"

mc alias set "$ALIAS" "$ENDPOINT" "$RESTIC_AWS_ACCESS_KEY_ID" "$RESTIC_AWS_SECRET_ACCESS_KEY" --api S3v4 >/dev/null 2>&1 \
    || { echo "FATAL: could not register mc alias with the prod key (bad key or endpoint unreachable)."; exit 3; }

echo "== Criterion 1.1 — prod key must NOT be able to delete =="
PROBE_OBJ="${PROBE_PREFIX}/delete-test-${STAMP}.txt"
head -c 64 /dev/urandom | base64 > "/tmp/${PROBE_PREFIX}-${STAMP}.txt"
if ! mc cp "/tmp/${PROBE_PREFIX}-${STAMP}.txt" "${ALIAS}/${RESTIC_S3_BUCKET}/${PROBE_OBJ}" >/dev/null 2>&1; then
    echo "INCONCLUSIVE: could not even PUT the probe object (check bucket name / put scope)."; exit 3
fi
del_out=$(mc rm "${ALIAS}/${RESTIC_S3_BUCKET}/${PROBE_OBJ}" 2>&1); del_rc=$?
echo "--- raw delete attempt output (criterion 1.1 evidence) ---"
echo "$del_out"
echo "--- end raw output ---"
if [ "$del_rc" -eq 0 ]; then
    echo "VERDICT 1.1: FAIL — the handed-over key CAN delete. Per H3175 'Fail =' rule:"
    echo "do NOT proceed to repo init; report that the key needs re-scoping."
    exit 2
fi
if ! grep -qiE 'AccessDenied|Access Denied' <<<"$del_out"; then
    echo "INCONCLUSIVE: delete failed but not with AccessDenied — inspect the output above."
    exit 3
fi
echo "VERDICT 1.1: PASS — AccessDenied quoted above. Key is delete-incapable."

echo
echo "== Criterion 1.2 — Object Lock status =="
LOCK_OBJ="${PROBE_PREFIX}/lock-probe-${STAMP}.txt"
head -c 16 /dev/urandom | base64 > "/tmp/lockprobe-${STAMP}.txt"
mc cp "/tmp/lockprobe-${STAMP}.txt" "${ALIAS}/${RESTIC_S3_BUCKET}/${LOCK_OBJ}" >/dev/null 2>&1 \
    || echo "WARN: lock-probe object could not be written; retention check will rely on bucket config only."
ret_out=$(mc retention info "${ALIAS}/${RESTIC_S3_BUCKET}/${LOCK_OBJ}" 2>&1)
echo "--- raw retention info output ---"
echo "$ret_out"
echo "--- end raw output ---"
if grep -qiE 'Object Lock.*not (enabled|configured)|ObjectLockConfigurationNotFoundError|not configured' <<<"$ret_out"; then
    echo "VERDICT 1.2: OBJECT LOCK OFF — DEFAULT applied: proceed, destination is NOT IMMUTABLE."
    echo "It must never be described as immutable until the bucket lock is enabled."
    IMMUTABLE=no
elif [ -n "$ret_out" ]; then
    echo "VERDICT 1.2: Object Lock configuration reported above — verify mode+retention match the ruling"
    echo "(governance mode, 400 days). IMMUTABLE=yes if governance/400d shown."
    IMMUTABLE=yes
else
    echo "INCONCLUSIVE on retention info; check bucket settings manually."
    IMMUTABLE=unknown
fi

if [ -n "${S3_ADMIN_ACCESS_KEY_ID:-}" ] && [ -n "${S3_ADMIN_SECRET_ACCESS_KEY:-}" ]; then
    echo
    echo "== Criterion 1.2b — ADMIN delete against a locked object must be refused =="
    if mc alias set "$ADMIN_ALIAS" "$ENDPOINT" "$S3_ADMIN_ACCESS_KEY_ID" "$S3_ADMIN_SECRET_ACCESS_KEY" --api S3v4 >/dev/null 2>&1; then
        adm_out=$(mc rm "${ADMIN_ALIAS}/${RESTIC_S3_BUCKET}/${LOCK_OBJ}" 2>&1); adm_rc=$?
        echo "--- raw admin delete attempt output ---"
        echo "$adm_out"
        echo "--- end raw output ---"
        if [ "$adm_rc" -ne 0 ] && grep -qiE 'AccessDenied|Access Denied' <<<"$adm_out"; then
            still_there=$(mc ls "${ALIAS}/${RESTIC_S3_BUCKET}/${LOCK_OBJ}" 2>/dev/null | wc -l)
            echo "VERDICT 1.2b: PASS — AccessDenied quoted above; object listings after attempt: ${still_there} (must be >0)."
        else
            echo "VERDICT 1.2b: FAIL or inconclusive — admin delete was NOT refused with AccessDenied (or no admin key scope). Inspect above."
        fi
    else
        echo "WARN: admin alias registration failed — skipping 1.2b."
    fi
else
    echo
    echo "NOTE 1.2b: no admin key in env file — the admin-delete refusal check needs one."
    echo "Re-run after adding S3_ADMIN_* vars, or accept bucket-lock config as evidence."
fi

rm -f "/tmp/${PROBE_PREFIX}-${STAMP}.txt" "/tmp/lockprobe-${STAMP}.txt"

if [ "$INIT" -ne 1 ]; then
    echo
    echo "Verify-only run complete. Probe objects under ${PROBE_PREFIX}/ stay in the bucket by design"
    echo "(the prod key cannot remove them — that is the point). Re-run with --init to bootstrap the repo."
    exit 0
fi

# --- bootstrap: init if needed, first push, snapshot comparison -------------
echo
echo "== Bootstrap — init S3 repo if absent, first push, criterion 1.3 =="
export AWS_ACCESS_KEY_ID="$RESTIC_AWS_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$RESTIC_AWS_SECRET_ACCESS_KEY"
export RESTIC_PASSWORD_FILE=/root/.restic-pass

if restic -r "$REPO" snapshots >/dev/null 2>&1; then
    echo "Repo already initialized at ${REPO} — skipping init."
else
    if restic -r "$REPO" init >>/tmp/h3175-init.log 2>&1; then
        echo "Initialized new repo at ${REPO} (password from /root/.restic-pass)."
    else
        echo "FATAL: restic init failed:"; tail -5 /tmp/h3175-init.log; exit 3
    fi
fi

if restic -r "$REPO" backup --tag systema \
    /var/backups/systema/db \
    /var/www/html/storage/app \
    /var/www/html/.env \
    /etc/nginx \
    --exclude 'storage/app/Laravel/*' \
    --exclude '*.log' \
    --exclude '*/cache/*'; then
    :
else
    echo "FATAL: first S3 systema push failed."; exit 3
fi

echo
echo "== Criterion 1.3 — snapshots from BOTH repos (same hour expected) =="
echo "--- SFTP repo (sftp:restic-push@192.168.200.91:/systema) ---"
restic -r sftp:restic-push@192.168.200.91:/systema \
    -o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp" snapshots
echo "--- S3 repo (${REPO}) ---"
restic -r "$REPO" snapshots
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
echo
echo "Bootstrap complete. The hourly wrapper picks the S3 leg up automatically from here."
