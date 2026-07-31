#!/usr/bin/env bash
# test_systema_schedule_run.sh — reproduction test for H1973 (stale-lock reclaim
# in systema-schedule-run.sh). Runs entirely in a temp dir, no prod access needed.
#
# Proves: (1) a fresh lock still correctly SKIPs (no regression on the existing
# overlap guard), and (2) a lock held past STALE_SECONDS is reclaimed and the
# guarded command actually runs, even though a separate process still holds an
# flock on the (now-unlinked) old inode -- the scenario a dead/wedged holder
# that can't be signaled (D-state) can't be told apart from in a black-box test.
#
# Usage: bash scripts/server_guards/sbin/test_systema_schedule_run.sh
#
# SAFETY (added 30-07-2026 after a live-fire incident): reap() inside the
# wrapper under test does a SYSTEM-WIDE `pgrep -f "artisan"` -- it is NOT
# scoped to this test's temp dir. Running this on a host that also has a real
# cron calling the real /usr/local/sbin/systema-schedule-run.sh WILL SIGKILL
# live production schedule:run processes if this test's (deliberately short)
# MAX_SECONDS/STALE_SECONDS window is shorter than how long those real
# processes have been running -- confirmed on prod (H1973 follow-up,
# 30-07-2026): a real `schedule:run` invocation got killed mid-tick, rc=137 in
# schedule.log, while this test ran with MAX_SECONDS=60 against a real prod
# cron tuned for 900s. No lasting damage that time (nothing was queued to
# post at that exact moment), but it was luck, not design. So this script
# refuses to run on any host where the real wrapper is installed, unless
# explicitly overridden.
set -euo pipefail

if [ -e /usr/local/sbin/systema-schedule-run.sh ] && [ -z "${H1973_TEST_ALLOW_MANAGED_HOST:-}" ]; then
  echo "REFUSING TO RUN: /usr/local/sbin/systema-schedule-run.sh exists on this host." >&2
  echo "This looks like a machine with a REAL cron calling the real wrapper -- reap()'s" >&2
  echo "system-wide 'pgrep -f artisan' can kill live schedule:run processes (see the" >&2
  echo "SAFETY note in this script's header for the incident this guards against)." >&2
  echo "Run this on a disposable dev box/CI runner instead. If you have verified no" >&2
  echo "live cron is calling the real wrapper right now, override with:" >&2
  echo "  H1973_TEST_ALLOW_MANAGED_HOST=1 bash $0" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$SCRIPT_DIR/systema-schedule-run.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

APP_DIR="$TMP/app"
mkdir -p "$APP_DIR/storage/framework"
LOCK="$APP_DIR/storage/framework/schedule-run.lock"

PHP_BIN="$TMP/fake-php"
RAN_MARKER="$TMP/ran"
cat > "$PHP_BIN" <<EOF
#!/usr/bin/env bash
echo "\$(date -u +%Y-%m-%dT%H:%M:%SZ) fake schedule:run invoked" >> "$RAN_MARKER"
exit 0
EOF
chmod +x "$PHP_BIN"

# Materialize the template with real substitutions, matching server_guards_apply.sh's
# ${body//@@$k@@/...} mechanism.
WRAPPER="$TMP/systema-schedule-run.sh"
body=$(cat "$SRC")
body=${body//@@APP_DIR@@/$APP_DIR}
body=${body//@@SCHEDULE_MAX_SECONDS@@/60}
body=${body//@@PHP_BIN@@/$PHP_BIN}
printf '%s\n' "$body" > "$WRAPPER"
chmod +x "$WRAPPER"

pass=0
fail=0
check() {
  if [ "$2" = "1" ]; then pass=$((pass+1)); echo "PASS: $1"
  else fail=$((fail+1)); echo "FAIL: $1"; fi
}

# --- Test 1: fresh lock -> SKIP (no regression) -----------------------------
flock -n "$LOCK" -c 'sleep 5' &
holder1=$!
sleep 0.3
out1=$(SYSTEMA_SCHEDULE_STALE_SECONDS=120 bash "$WRAPPER" 2>&1 || true)
wait "$holder1" 2>/dev/null || true
echo "$out1"
case "$out1" in
  *"SKIP: previous schedule:run still holds the lock"*) check "fresh lock skips" 1 ;;
  *) check "fresh lock skips" 0 ;;
esac
case "$out1" in
  *"STALE LOCK"*) check "fresh lock does not falsely reclaim" 0 ;;
  *) check "fresh lock does not falsely reclaim" 1 ;;
esac
rm -f "$RAN_MARKER"

# --- Test 2: lock older than STALE_SECONDS -> reclaim + command runs --------
: > "$LOCK"
touch -d '-10 minutes' "$LOCK"
flock -n "$LOCK" -c 'sleep 5' &
holder2=$!
sleep 0.3
out2=$(SYSTEMA_SCHEDULE_STALE_SECONDS=5 bash "$WRAPPER" 2>&1 || true)
echo "$out2"
case "$out2" in
  *"STALE LOCK"*"reclaiming"*) check "stale lock is reclaimed" 1 ;;
  *) check "stale lock is reclaimed" 0 ;;
esac
if [ -f "$RAN_MARKER" ]; then check "guarded command ran after reclaim" 1
else check "guarded command ran after reclaim" 0; fi
kill "$holder2" 2>/dev/null || true
wait "$holder2" 2>/dev/null || true

echo
echo "== $pass passed / $fail failed =="
[ "$fail" -eq 0 ]
