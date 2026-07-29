#!/bin/bash
# systema-schedule-run.sh — the ONLY way cron should invoke Laravel's scheduler.
#
# MANAGED FILE — installed by scripts/server_guards_apply.sh from
# scripts/server_guards/sbin/systema-schedule-run.sh. Edit the repo copy, not the
# server copy: `php artisan guards:verify` reports any hand-edit as drift.
#
# Three guarantees the bare `php artisan schedule:run` cron line did not give:
#   1. flock -n  : at most ONE scheduler run exists at any moment. A slow run makes
#                  the next minute SKIP, instead of stacking a second ~200 MB chain.
#                  This is what turns a slow command into a delay instead of a hang.
#   2. timeout   : a wedged run cannot hold the lock forever; it dies and the next
#                  minute proceeds. Sized above the longest legitimate scheduled
#                  command (telegram-harvest:roster-groups, 600 s).
#   3. reaper    : kills scheduler-spawned artisan processes that outlived the cap.
#                  On 28-07-2026 the OOM killer took out cron and left ~20 orphaned
#                  php processes that nothing would ever reap; this is that net.
#
# Long-lived DAEMONS are never touched: horizon*, reverb, queue:work and the
# MadelineProto worker are supervisor-managed and legitimately run forever.
set -uo pipefail

APP_DIR=${SYSTEMA_APP_DIR:-@@APP_DIR@@}
LOCK="$APP_DIR/storage/framework/schedule-run.lock"
MAX_SECONDS=${SYSTEMA_SCHEDULE_MAX_SECONDS:-@@SCHEDULE_MAX_SECONDS@@}
DAEMON_RE='horizon|reverb|queue:work|MadelineProto'
ts() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }

# --- reaper: runs every minute, before taking the lock ---------------------
# Anything invoked through artisan that has outlived MAX_SECONDS and is not a
# known daemon is a straggler. TERM first; a straggler still alive on the next
# pass (i.e. > 2x the cap) gets KILL.
reap() {
  local pid args et
  for pid in $(pgrep -f "artisan" 2>/dev/null); do
    args=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null) || continue
    [ -z "$args" ] && continue
    echo "$args" | grep -qE "$DAEMON_RE" && continue
    et=$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d ' ')
    [ -z "$et" ] && continue
    if [ "$et" -gt $((MAX_SECONDS * 2)) ]; then
      echo "$(ts) REAP KILL pid=$pid age=${et}s :: ${args:0:140}"
      kill -KILL "$pid" 2>/dev/null || true
    elif [ "$et" -gt "$MAX_SECONDS" ]; then
      echo "$(ts) REAP TERM pid=$pid age=${et}s :: ${args:0:140}"
      kill -TERM "$pid" 2>/dev/null || true
    fi
  done
}
reap

# --- single-flight lock ----------------------------------------------------
exec 9>"$LOCK" 2>/dev/null || exit 0
if ! flock -n 9; then
  echo "$(ts) SKIP: previous schedule:run still holds the lock"
  exit 0
fi

cd "$APP_DIR" || exit 1
timeout -k 30s "${MAX_SECONDS}s" @@PHP_BIN@@ artisan schedule:run
rc=$?
if [ "$rc" -ge 124 ]; then
  echo "$(ts) TIMEOUT: schedule:run exceeded ${MAX_SECONDS}s (rc=$rc) — reaping its children"
  reap
fi
exit 0
