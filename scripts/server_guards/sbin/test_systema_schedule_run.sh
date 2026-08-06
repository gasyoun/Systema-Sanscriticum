#!/usr/bin/env bash
# test_systema_schedule_run.sh — reproduction test for H1973 (stale-lock reclaim
# in systema-schedule-run.sh). Runs entirely in a temp dir, no prod access needed.
#
# Proves: (1) a fresh lock still correctly SKIPs (no regression on the existing
# overlap guard), (2) a lock held past STALE_SECONDS is reclaimed and the
# guarded command actually runs, even though a separate process still holds an
# flock on the (now-unlinked) old inode -- the scenario a dead/wedged holder
# that can't be signaled (D-state) can't be told apart from in a black-box test,
# and (3) an unrelated host process whose argv resembles Artisan survives.
#
# Usage: bash scripts/server_guards/sbin/test_systema_schedule_run.sh
#
# The wrapper is now process-group scoped and this test does not signal any
# process except the synthetic background process it creates and cleans up.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$SCRIPT_DIR/systema-schedule-run.sh"
TMP="$(mktemp -d)"
unrelated_pid=""
cleanup() {
  if [ -n "$unrelated_pid" ]; then
    kill "$unrelated_pid" 2>/dev/null || true
    wait "$unrelated_pid" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

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

# --- Test 3: unrelated Artisan-like process is never signaled --------------
# `exec -a` makes pgrep -f see "php artisan unrelated:work". The old global
# reaper killed this process once its age exceeded MAX_SECONDS; the wrapper may
# now terminate only the process group it started through GNU timeout.
bash -c 'exec -a "php artisan unrelated:work" sleep 30' &
unrelated_pid=$!
sleep 2
out3=$(SYSTEMA_SCHEDULE_MAX_SECONDS=1 bash "$WRAPPER" 2>&1 || true)
echo "$out3"
if kill -0 "$unrelated_pid" 2>/dev/null; then
  check "unrelated Artisan-like process survives" 1
else
  check "unrelated Artisan-like process survives" 0
fi
kill "$unrelated_pid" 2>/dev/null || true
wait "$unrelated_pid" 2>/dev/null || true
unrelated_pid=""

echo
echo "== $pass passed / $fail failed =="
[ "$fail" -eq 0 ]
