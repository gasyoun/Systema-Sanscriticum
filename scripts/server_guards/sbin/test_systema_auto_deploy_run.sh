#!/usr/bin/env bash
# test_systema_auto_deploy_run.sh — reproduction test for H2149 (a tripped
# auto-deploy fuse must RE-CHECK itself instead of staying tripped forever).
# Runs entirely in a temp dir against fakes; no prod access, no network, no cron.
#
# The defect it pins: the fuse is a FILE, but the condition that set it is a
# STATE. On 01-08-2026 preflight caught a dirty config/marathon_landing_copy.php
# at 19:28; the identical text landed in git via #1045 minutes later. The
# condition was gone, the file was not, and `[ -f "$BREAKER" ] && exit 0` muted
# every subsequent tick silently and permanently — prod sat 5 commits behind
# until a human deleted the file.
#
# Proves, in order:
#   1. HARD trip (no soft tag) is NEVER auto-cleared — the safety property.
#   2. SOFT trip younger than RETRY_AFTER_MIN waits (no thrashing in-tick).
#   3. SOFT trip + healthy box + elapsed pause -> fuse cleared, deploy re-runs.
#   4. SOFT trip + UNHEALTHY box -> NOT cleared (never resume into a sick host).
#   5. Retries are capped: after MAX_AUTO_RETRIES the fuse stays and says so.
#   6. A successful deploy resets the retry counter.
#
# Usage: bash scripts/server_guards/sbin/test_systema_auto_deploy_run.sh
#
# SAFETY: unlike test_systema_schedule_run.sh this wrapper never pgreps or kills
# anything, and every external command it touches (git/curl/systemctl/deploy.sh)
# is replaced by a fake on PATH inside the temp dir. It is therefore safe to run
# on a managed host — but it still refuses to touch the real APP_DIR because the
# wrapper under test is materialised with APP_DIR pointing into the temp dir.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Override to point the harness at another copy of the wrapper — used to prove
# this test actually FAILS against the pre-H2149 version (a test that passes on
# both the broken and the fixed script proves nothing):
#   git show origin/main:scripts/server_guards/sbin/systema-auto-deploy-run.sh > /tmp/old.sh
#   H2149_WRAPPER_SRC=/tmp/old.sh bash scripts/server_guards/sbin/test_systema_auto_deploy_run.sh
SRC="${H2149_WRAPPER_SRC:-$SCRIPT_DIR/systema-auto-deploy-run.sh}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

APP_DIR="$TMP/app"
mkdir -p "$APP_DIR/storage/framework" "$APP_DIR/storage/logs" "$APP_DIR/storage/app/server_guards"
BREAKER="$APP_DIR/storage/auto_deploy.disabled"
RETRIES="$APP_DIR/storage/framework/auto-deploy-retries"
HISTORY="$APP_DIR/storage/logs/auto_deploy_breaker_history.log"
DEPLOY_MARKER="$TMP/deployed"

# ── Fakes on PATH ───────────────────────────────────────────────────────────
BIN="$TMP/bin"
mkdir -p "$BIN"

# git: HEAD != origin/main so the wrapper always believes there is work to do.
cat > "$BIN/git" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  "fetch -q origin") exit 0 ;;
  "rev-parse HEAD") echo 1111111111111111111111111111111111111111 ;;
  "rev-parse origin/main") echo 2222222222222222222222222222222222222222 ;;
  "rev-parse --short "*) echo 1111111 ;;
  "diff --name-only "*) exit 0 ;;
  *) exit 0 ;;
esac
EOF

# curl: health smoke. HEALTH_CODE steers it (200 = healthy).
cat > "$BIN/curl" <<EOF
#!/usr/bin/env bash
echo "\${HEALTH_CODE:-200}"
EOF

# systemctl / supervisorctl / crontab: always fine, so health hinges on curl only.
cat > "$BIN/systemctl" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat > "$BIN/supervisorctl" <<'EOF'
#!/usr/bin/env bash
echo "horizon RUNNING pid 1, uptime 1:00:00"
EOF
cat > "$BIN/crontab" <<'EOF'
#!/usr/bin/env bash
echo "*/30 * * * * /usr/local/sbin/systema-auto-deploy-run.sh"
EOF
# flock: only faked where the host has none (Git Bash on Windows). On Linux the
# REAL flock is left in place, so the overlap guard keeps being exercised for
# real. Without this the wrapper dies at `flock -n 9 || exit 0` and every
# assertion in this file passes vacuously — which is exactly how the first
# draft of this test "passed" while proving nothing.
if ! command -v flock >/dev/null 2>&1; then
  cat > "$BIN/flock" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
fi

chmod +x "$BIN"/*

# deploy.sh: DEPLOY_RC steers success/failure; records that it ran.
DEPLOY_SH="$TMP/deploy.sh"
cat > "$DEPLOY_SH" <<EOF
#!/usr/bin/env bash
echo "fake deploy invoked" >> "$DEPLOY_MARKER"
exit \${DEPLOY_RC:-0}
EOF
chmod +x "$DEPLOY_SH"

# health_check reads /proc/meminfo directly. Point it at a fixture instead of the
# real file: /proc does not exist on Windows/macOS at all, and on a busy Linux CI
# box a real reading could dip under the floor and flake the run. HEALTH_CODE
# (the smoke) stays the single deliberate health lever.
MIN_MB=1
MEMINFO="$TMP/meminfo"
printf 'MemTotal:       16777216 kB\nMemAvailable:    8388608 kB\n' > "$MEMINFO"

# Materialize the template, matching server_guards_apply.sh's ${body//@@k@@/v}.
WRAPPER="$TMP/systema-auto-deploy-run.sh"
body=$(cat "$SRC")
body=${body//@@APP_DIR@@/$APP_DIR}
body=${body//@@AUTO_DEPLOY_MAX_SECONDS@@/60}
body=${body//@@AUTO_DEPLOY_MIN_AVAILABLE_MB@@/$MIN_MB}
body=${body//@@AUTO_DEPLOY_SMOKE_URL@@/http://localhost/}
body=${body//@@PHP_VERSION@@/8.3}
body=${body//@@AUTO_DEPLOY_RETRY_AFTER_MINUTES@@/30}
body=${body//@@AUTO_DEPLOY_MAX_AUTO_RETRIES@@/3}

# The wrapper deliberately HARD-RESETS PATH (cron gives it a stunted one), which
# also throws away this test's fake bin — every external command would resolve to
# the host's real git/curl/systemctl. Combined with `exec 9>"$LOCK" 2>/dev/null`,
# which silences stderr for the rest of the run, a missing command exits 0 in
# total silence: the first draft of this test "passed" 9 assertions that way
# without the wrapper executing a single line of fuse logic. So prepend the fake
# bin to that exact line. Everything else about PATH stays as shipped.
body=${body//export PATH=\/usr\/local\/sbin:/export PATH=$BIN:\/usr\/local\/sbin:}
body=${body//\/proc\/meminfo/$MEMINFO}
case "$body" in
  *"export PATH=$BIN:"*) : ;;
  *) echo "FAIL: could not inject the fake bin into the wrapper's PATH line" >&2; exit 1 ;;
esac

printf '%s\n' "$body" > "$WRAPPER"
chmod +x "$WRAPPER"

case "$(cat "$WRAPPER")" in
  *@@*) echo "FAIL: unsubstituted @@…@@ left in the materialised wrapper" >&2; exit 1 ;;
esac

run_wrapper() {
  env PATH="$BIN:$PATH" \
      SYSTEMA_APP_DIR="$APP_DIR" \
      SYSTEMA_DEPLOY_SH="$DEPLOY_SH" \
      SYSTEMA_APP_USER="$(id -un)" \
      "$@" bash "$WRAPPER" >"$TMP/out" 2>&1 || true
}

# Backdate the fuse so the RETRY_AFTER_MIN pause is considered elapsed.
age_breaker() { touch -d '2 hours ago' "$BREAKER"; }

pass=0
fail=0
check() {
  if [ "$2" = "1" ]; then pass=$((pass+1)); echo "PASS: $1"
  else fail=$((fail+1)); echo "FAIL: $1"; echo "---- wrapper output ----"; cat "$TMP/out"; echo "------------------------"; fi
}

reset_state() { rm -f "$BREAKER" "$RETRIES" "$DEPLOY_MARKER"; }

# ── 1. Hard trip is never auto-cleared ──────────────────────────────────────
reset_state
echo "2026-08-01T19:30:03Z деплой прошёл, но сервер нездоров: smoke:502" > "$BREAKER"
age_breaker
run_wrapper
check "hard trip (no soft tag) keeps the fuse" "$([ -f "$BREAKER" ] && echo 1 || echo 0)"
check "hard trip does not run a deploy" "$([ ! -f "$DEPLOY_MARKER" ] && echo 1 || echo 0)"

# ── 2. Soft trip younger than the pause waits ───────────────────────────────
reset_state
echo "2026-08-01T19:30:03Z [blocked-preflight] грязное дерево" > "$BREAKER"   # mtime = now
run_wrapper
check "fresh soft trip keeps the fuse (pause not elapsed)" "$([ -f "$BREAKER" ] && echo 1 || echo 0)"
check "fresh soft trip does not run a deploy" "$([ ! -f "$DEPLOY_MARKER" ] && echo 1 || echo 0)"

# ── 3. Soft trip + healthy + elapsed pause -> cleared, deploy re-runs ───────
reset_state
echo "2026-08-01T19:30:03Z [blocked-preflight] грязное дерево" > "$BREAKER"
age_breaker
run_wrapper
check "aged soft trip clears the fuse" "$([ ! -f "$BREAKER" ] && echo 1 || echo 0)"
check "aged soft trip re-runs the deploy" "$([ -f "$DEPLOY_MARKER" ] && echo 1 || echo 0)"
check "cleared fuse text is preserved in the history log" \
  "$(grep -q 'blocked-preflight' "$HISTORY" 2>/dev/null && echo 1 || echo 0)"
check "successful deploy resets the retry counter" "$([ ! -f "$RETRIES" ] && echo 1 || echo 0)"

# ── 4. Soft trip on an UNHEALTHY box is not cleared ─────────────────────────
reset_state
echo "2026-08-01T19:30:03Z [timeout-alive] таймаут" > "$BREAKER"
age_breaker
run_wrapper HEALTH_CODE=502
check "unhealthy host keeps the fuse even for a soft trip" "$([ -f "$BREAKER" ] && echo 1 || echo 0)"
check "unhealthy host does not run a deploy" "$([ ! -f "$DEPLOY_MARKER" ] && echo 1 || echo 0)"

# ── 5. Retries are capped ───────────────────────────────────────────────────
# Deploy keeps failing, so each pass re-trips softly; the counter must stop it.
reset_state
echo "2026-08-01T19:30:03Z [blocked-preflight] грязное дерево" > "$BREAKER"
for _ in 1 2 3 4 5; do
  age_breaker
  run_wrapper DEPLOY_RC=1
done
check "capped: fuse still present after exceeding MAX_AUTO_RETRIES" \
  "$([ -f "$BREAKER" ] && echo 1 || echo 0)"
check "capped: counter stopped at MAX_AUTO_RETRIES (3)" \
  "$([ "$(cat "$RETRIES" 2>/dev/null || echo 0)" = "3" ] && echo 1 || echo 0)"
check "capped: fuse says auto-retry gave up" \
  "$(grep -q 'auto-retry исчерпан' "$BREAKER" && echo 1 || echo 0)"

# ── 6. Exhausted cap stays exhausted (no silent re-arm) ─────────────────────
age_breaker
run_wrapper
check "exhausted cap does not clear on a later healthy tick" \
  "$([ -f "$BREAKER" ] && echo 1 || echo 0)"

echo
echo "passed=$pass failed=$fail"
[ "$fail" -eq 0 ]
