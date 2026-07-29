#!/bin/bash
# systema-watchdog-run.sh <artisan-command> <lock-name> [max-seconds]
#
# MANAGED FILE — installed by scripts/server_guards_apply.sh from
# scripts/server_guards/sbin/systema-watchdog-run.sh. Edit the repo copy, not the
# server copy: `php artisan guards:verify` reports any hand-edit as drift.
#
# Runs ONE cheap watchdog command completely independently of the main
# scheduler chain. Separate lock, separate timeout, separate fate.
# Both current callers measure ~1 s, so the 120 s cap is ~100x headroom.
set -uo pipefail

CMD="${1:?usage: systema-watchdog-run.sh <artisan command> <lock name> [max seconds]}"
LOCKNAME="${2:?lock name required}"
MAX="${3:-120}"
APP_DIR=${SYSTEMA_APP_DIR:-@@APP_DIR@@}
LOCK="$APP_DIR/storage/framework/watchdog-$LOCKNAME.lock"

exec 9>"$LOCK" 2>/dev/null || exit 0
flock -n 9 || exit 0          # previous watchdog still running: silently skip

cd "$APP_DIR" || exit 1
timeout -k 10s "${MAX}s" @@PHP_BIN@@ artisan $CMD >/dev/null 2>&1
rc=$?
if [ "$rc" -ge 124 ]; then
  echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') WATCHDOG TIMEOUT: '$CMD' exceeded ${MAX}s"
fi
exit 0
