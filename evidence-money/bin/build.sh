#!/bin/sh
# H6326 — nightly rebuild of the evidence-money витрина on vps92.
# Runs as root from the managed crontab (scripts/server_guards/cron/root.crontab).
# Read-only against MySQL (evidence_ro, SELECT-only). Failure is loud: exit 1,
# logged to /var/log/evidence-money-build.cron.log (guards:verify picks up diff).
set -eu
APP_DIR=/opt/evidence-money
LOG_TAG=evidence-money-build
cd "$APP_DIR"

echo "[$(date -Is)] rebuild start"
npm run build >> /var/log/$LOG_TAG.cron.log 2>&1

# Post-build invariant: the fixed parity month figure must be present in the
# static output (September 2026 = 722121, see bin/parity_check.sh). Evidence
# formats the rendered figure with a thousands separator — accept both forms.
if ! grep -rqE "722[,.]?121" build/ 2>/dev/null; then
  echo "[$(date -Is)] FAIL: parity figure 722121 (2026-09 canon) absent from build output" >> /var/log/$LOG_TAG.cron.log
  exit 1
fi
echo "[$(date -Is)] rebuild ok"
