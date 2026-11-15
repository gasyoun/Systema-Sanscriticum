#!/usr/bin/env bash
# test_deploy_guards_apply.sh — H7060 reproduction test for incident row 9.
#
# Row 9: a legitimate commit edited a managed guard file; deploy.sh ran
# guards:verify, printed drift, and never applied it. The auto-deploy fuse
# burned after 26 alerts in 36 hours and no system file ever changed.
#
# This test proves, without root and without touching the host:
#   1. --files-only --root is a real sandbox: the first apply installs every
#      manifest file under the temp root.
#   2. A second apply is a no-op: zero `changed` lines, and --dry-run --diff
#      reports zero differing files.
#   3. The row-9 scenario: edit a managed template (the "commit"), apply once
#      (the deploy) changes exactly that file, apply again is a no-op.
#   4. scripts/deploy_guards_sync.sh announces and applies drift, copies every
#      `changed` line to deploys.log, and a second deploy run does not apply.
#   5. --verify-only (the rollback path) never applies anything.
#
# Usage: bash scripts/server_guards/sbin/test_deploy_guards_apply.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

# ── 1–3. Real apply, sandbox root ───────────────────────────────────────────
REPO="$TMP/repo"
mkdir -p "$REPO/scripts"
cp "$REPO_ROOT/scripts/server_guards_apply.sh" "$REPO/scripts/"
cp "$REPO_ROOT/scripts/server_guards.conf" "$REPO/scripts/"
cp -a "$REPO_ROOT/scripts/server_guards" "$REPO/scripts/"
ROOT="$TMP/machine"
mkdir -p "$ROOT"

APPLY="$REPO/scripts/server_guards_apply.sh"
run_apply() { bash "$APPLY" --files-only --root "$ROOT" --no-install "$@"; }

OUT1="$TMP/apply1.out"
run_apply > "$OUT1"
grep -q 'files-only:' "$OUT1" || fail "first apply did not report files-only summary"
[ -f "$ROOT/etc/systemd/system/cron.service.d/memory-cap.conf" ] \
  || fail "first apply did not install the managed files under --root"
changed1=$(grep -c 'changed' "$OUT1" || true)
[ "$changed1" -gt 0 ] || fail "first apply reported zero changes on an empty root"

OUT2="$TMP/apply2.out"
run_apply > "$OUT2"
changed2=$(grep -c 'changed' "$OUT2" || true)
[ "$changed2" -eq 0 ] || fail "idempotency: second apply changed $changed2 file(s)"
grep -q 'files-only: 0 изменённых файлов' "$OUT2" \
  || fail "idempotency: second apply did not report zero changed files"

DIFF2="$TMP/diff2.out"
run_apply --dry-run --diff > "$DIFF2"
grep -q 'dry-run: 0 файлов разошлись' "$DIFF2" \
  || fail "idempotency: dry-run diff is not zero after the second apply"
if grep -q '^      --- ' "$DIFF2"; then
  fail "idempotency: dry-run still prints a unified diff"
fi

# Row-9 replay: a commit edits one managed template.
TPL="$REPO/scripts/server_guards/systemd/cron.service.d/memory-cap.conf"
printf '\n# H7060 fixture: legitimate commit edit\n' >> "$TPL"

OUT3="$TMP/apply3.out"
run_apply > "$OUT3"
grep -q 'changed' "$OUT3" || fail "row 9: apply did not notice the edited managed template"
[ "$(grep -c 'changed' "$OUT3" || true)" -eq 1 ] \
  || fail "row 9: apply changed more than the single edited managed file"

OUT4="$TMP/apply4.out"
run_apply > "$OUT4"
[ "$(grep -c 'changed' "$OUT4" || true)" -eq 0 ] \
  || fail "row 9: second deploy run after the edit was not a no-op"
run_apply --dry-run --diff > "$TMP/diff4.out"
grep -q 'dry-run: 0 файлов разошлись' "$TMP/diff4.out" \
  || fail "row 9: second deploy run still has a non-zero diff"

# ── 4–5. deploy_guards_sync.sh with fake php/apply ──────────────────────────
SYNC="$REPO_ROOT/scripts/deploy_guards_sync.sh"
[ -f "$SYNC" ] || fail "missing scripts/deploy_guards_sync.sh"

APP="$TMP/app"
STATE="$TMP/state"
BIN="$TMP/bin"
mkdir -p "$APP/storage/logs" "$APP/scripts" "$STATE" "$BIN"
: > "$APP/artisan"
APPLY_COUNT="$STATE/apply.count"
: > "$APPLY_COUNT"

cat > "$BIN/php" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *"artisan guards:verify"*)
    [ -f "$STATE/guard.ok" ] && exit 0
    exit 1 ;;
  *) exit 0 ;;
esac
EOF
export STATE
cat > "$TMP/fake-apply.sh" <<'EOF'
#!/usr/bin/env bash
echo x >> "$STATE/apply.count"
case "$*" in
  *--dry-run*) echo "  changed /etc/systemd/system/x.conf (dry-run)" ;;
  *) echo "  changed /etc/systemd/system/x.conf"; touch "$STATE/guard.ok" ;;
esac
exit 0
EOF
chmod +x "$BIN/php" "$TMP/fake-apply.sh"

run_sync() {
  APP_DIR="$APP" APP_USER=root PHP_BIN=php \
  SERVER_GUARDS_APPLY_SH="$TMP/fake-apply.sh" \
  DEPLOY_LOG="$APP/storage/logs/deploys.log" \
  PATH="$BIN:$PATH" bash "$SYNC" "$@"
}

# 5. Rollback path: verify-only sees drift, never applies.
set +e
run_sync --verify-only > "$TMP/sync-verify.out" 2>&1
rc=$?
set -e
[ "$rc" -eq 75 ] || fail "verify-only expected exit 75 on drift, got $rc"
[ "$(wc -l < "$APPLY_COUNT")" -eq 0 ] || fail "verify-only applied a file (rollback path changed)"

# 4. First deploy run: dry-run diff, apply, re-verify green, log every change.
run_sync > "$TMP/sync1.out" 2>&1 || fail "first guards sync failed: $(tail -3 "$TMP/sync1.out")"
[ "$(wc -l < "$APPLY_COUNT")" -eq 2 ] || fail "first sync must call apply twice (dry-run + apply)"
grep -q 'GUARDS DRIFT: dry-run diff' "$TMP/sync1.out" || fail "dry-run diff was not announced"
grep -q 'GUARDS APPLY: /etc/systemd/system/x.conf' "$APP/storage/logs/deploys.log" \
  || fail "system change was not copied to deploys.log"
[ "$(grep -c 'GUARDS APPLY:' "$APP/storage/logs/deploys.log")" -eq 1 ] \
  || fail "expected exactly one announced system change"

# Second deploy run: verify is green, apply is NOT called again.
run_sync > "$TMP/sync2.out" 2>&1 || fail "second guards sync failed"
[ "$(wc -l < "$APPLY_COUNT")" -eq 2 ] || fail "second sync re-applied guards (not idempotent)"
grep -q 'расхождений нет' "$TMP/sync2.out" || fail "second sync did not report clean guards"

printf 'PASS: H7060 row-9 replay — apply idempotent, second deploy run no-op, rollback verify-only\n'
