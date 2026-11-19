#!/usr/bin/env bash
# test_managed_guards_apply_on_deploy.sh — H7060 behavioral suite for
# scripts/server_guards/sbin/managed-guards-apply-on-deploy.sh (the step deploy.sh
# runs when guards:verify reports managed-file drift).
#
# Runs entirely in a temp dir against a stubbed server_guards_apply.sh; no prod
# access, no root, no systemd, nothing outside $TMP is touched or read except the
# repo copy of the helper and deploy.sh.
#
# The incident it replays (row 9, 18-10-2026): a legitimate commit edited a
# managed file; deploy.sh called guards:verify, saw the drift, printed the banner
# and exited 0 (#1143) without ever calling server_guards_apply.sh. Nothing
# healed, 26 alerts over 36 hours fixed nothing, and the retry wrapper kept
# burning cycles around a drift that only a human could clear.
#
# Covers the locked acceptance of H7060:
#   1. ROW-9 REPLAY, FIRST DEPLOY: live file != repo file -> the helper ANNOUNCES
#      the unified diff, applies, and ends clean (exit 0, "guards:verify чист").
#   2. SECOND DEPLOY IN A ROW IS A NO-OP: same tree, run again -> "no-op", zero
#      files diverged, and the real apply is NOT invoked a second time
#      (idempotence is proven, not inferred).
#   3. NON-ROOT: drift is found but apply is refused (exit 4) — the diff is still
#      announced, nothing is applied.
#   4. APPLY FAILS / DRIFT SURVIVES: exit 3, so deploy.sh keeps today's banner.
#   5. APPLY SCRIPT MISSING: exit 5, no silent skip.
#   6. WIRING: deploy.sh is bash-clean, calls this helper, and the call sits
#      BEFORE the drift banner (so the banner means "drift survived the apply").
#
# Before/after evidence: run against the pre-H7060 deploy.sh with
#   DEPLOY_SH_UNDER_TEST=<old deploy.sh> bash test_managed_guards_apply_on_deploy.sh
# and case 6 fails (no call site), which is what makes this a reproduction test.
#
# KEEP_TMP=1 preserves the artifact tree for debugging.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
HELPER="$SCRIPT_DIR/managed-guards-apply-on-deploy.sh"
DEPLOY_SH="${DEPLOY_SH_UNDER_TEST:-$REPO_ROOT/deploy.sh}"

TMP="$(mktemp -d)"
cleanup() {
  if [ -n "${KEEP_TMP:-}" ]; then echo "KEEP_TMP artifacts at: $TMP"
  else rm -rf "$TMP"; fi
}
trap cleanup EXIT

pass=0
fail=0
check() { # check <label> <1|0>
  if [ "$2" = "1" ]; then pass=$((pass + 1)); echo "PASS: $1"
  else fail=$((fail + 1)); echo "FAIL: $1"; fi
}
contains() { # contains <file> <fixed string> -> 1|0
  if grep -qF -- "$2" "$1" 2>/dev/null; then echo 1; else echo 0; fi
}
lacks() { # lacks <file> <fixed string> -> 1|0
  if grep -qF -- "$2" "$1" 2>/dev/null; then echo 0; else echo 1; fi
}

# ── Stub server_guards_apply.sh: live file vs repo file, same CLI as the real one
# (--dry-run/--diff announce; plain run applies; ends on a verify-shaped verdict).
mkdir -p "$TMP/state"
: > "$TMP/state/live.conf"
: > "$TMP/state/want.conf"
: > "$TMP/state/real_apply_count"
printf 'nofile 1024\n' > "$TMP/state/live.conf"   # live machine: the old, pre-incident value
printf 'nofile 65535\n' > "$TMP/state/want.conf"  # repo (managed) value

FAKE_APPLY="$TMP/fake_server_guards_apply.sh"
cat > "$FAKE_APPLY" <<'FAKE'
#!/usr/bin/env bash
set -uo pipefail
dry=0
show_diff=0
for a in "$@"; do
  case "$a" in
    --dry-run) dry=1 ;;
    --diff) show_diff=1 ;;
  esac
done
live="$STATE_DIR/live.conf"
want="$STATE_DIR/want.conf"

if [ "$dry" = 1 ]; then
  if cmp -s "$live" "$want"; then
    echo "  ok      $live"
    echo "  dry-run: 0 файлов разошлись"
  else
    if [ "$show_diff" = 1 ]; then
      diff -u "$live" "$want" | sed 's/^/      /' || true
    fi
    echo "  changed $live (dry-run)"
    echo "  dry-run: 1 файлов разошлись"
  fi
  exit 0
fi

printf 'x\n' >> "$STATE_DIR/real_apply_count"
if [ "${FAKE_APPLY_FAIL:-0}" = 1 ]; then
  printf '  \033[1;31m✖ guards:verify недоволен — см. список выше\033[0m\n' >&2
  exit 1
fi
if cmp -s "$live" "$want"; then
  echo "  ok      $live"
  printf '\n\033[1;32m✔ Предохранители на месте (0 изменений в этот прогон).\033[0m\n'
else
  cp "$want" "$live"
  echo "  changed $live"
  printf '\n\033[1;32m✔ Предохранители на месте (1 изменений в этот прогон).\033[0m\n'
fi
exit 0
FAKE
chmod +x "$FAKE_APPLY"

run_helper() { # run_helper <logfile> [env ...] -> helper rc
  local log="$1"; shift
  env STATE_DIR="$TMP/state" SERVER_GUARDS_APPLY_SH="$FAKE_APPLY" "$@" \
    bash "$HELPER" >"$log" 2>&1
  echo $?
}

# ── 1. Row-9 replay: first deploy announces, applies, ends clean ─────────────
rc="$(run_helper "$TMP/run1.log" MANAGED_APPLY_UID_OVERRIDE=0)"
check "1a row-9 replay: helper exits 0 (drift closed by the deploy)" "$([ "$rc" = 0 ] && echo 1 || echo 0)"
check "1b row-9 replay: unified diff announced before apply" "$(contains "$TMP/run1.log" '@@ -1 +1 @@')"
check "1c row-9 replay: apply invoked (dry-run then real)" "$(contains "$TMP/run1.log" 'apply 1 файл')"
check "1d row-9 replay: verdict is clean" "$(contains "$TMP/run1.log" 'guards:verify чист')"
check "1e row-9 replay: live file now equals the repo value" \
  "$(cmp -s "$TMP/state/live.conf" "$TMP/state/want.conf" && echo 1 || echo 0)"
check "1f row-9 replay: exactly one real apply ran" \
  "$([ "$(wc -l < "$TMP/state/real_apply_count" | tr -d ' ')" = 1 ] && echo 1 || echo 0)"

# ── 2. Second deploy in a row: no-op, zero divergence, no second apply ───────
rc="$(run_helper "$TMP/run2.log" MANAGED_APPLY_UID_OVERRIDE=0)"
check "2a idempotence: second run exits 0" "$([ "$rc" = 0 ] && echo 1 || echo 0)"
check "2b idempotence: second run reports no-op" "$(contains "$TMP/run2.log" 'no-op')"
check "2c idempotence: second run shows 0 files diverged" \
  "$(contains "$TMP/run2.log" 'dry-run: 0 файлов разошлись')"
check "2d idempotence: real apply NOT invoked again" \
  "$([ "$(wc -l < "$TMP/state/real_apply_count" | tr -d ' ')" = 1 ] && echo 1 || echo 0)"

# ── 3. Non-root: announce, refuse to apply, exit 4 ──────────────────────────
printf 'nofile 1024\n' > "$TMP/state/live.conf"   # drift again
rc="$(run_helper "$TMP/run3.log" MANAGED_APPLY_UID_OVERRIDE=1000)"
check "3a non-root: exits 4 (apply refused, deploy keeps the banner)" "$([ "$rc" = 4 ] && echo 1 || echo 0)"
check "3b non-root: diff is still announced" "$(contains "$TMP/run3.log" '@@ -1 +1 @@')"
check "3c non-root: no real apply happened" \
  "$([ "$(wc -l < "$TMP/state/real_apply_count" | tr -d ' ')" = 1 ] && echo 1 || echo 0)"
check "3d non-root: says why in plain words" "$(contains "$TMP/run3.log" 'не root')"

# ── 4. Apply fails / drift survives: exit 3, loud ───────────────────────────
rc="$(run_helper "$TMP/run4.log" MANAGED_APPLY_UID_OVERRIDE=0 FAKE_APPLY_FAIL=1)"
check "4a apply failure: exits 3 (deploy.sh keeps today's banner)" "$([ "$rc" = 3 ] && echo 1 || echo 0)"
check "4b apply failure: names the failure" "$(contains "$TMP/run4.log" 'apply rc=1')"

# ── 5. Apply script missing: exit 5, never a silent skip ────────────────────
rc="$(run_helper "$TMP/run5.log" SERVER_GUARDS_APPLY_SH="$TMP/nope.sh")"
check "5a missing apply script: exits 5" "$([ "$rc" = 5 ] && echo 1 || echo 0)"
check "5b missing apply script: loud" "$(contains "$TMP/run5.log" 'применить нечем')"

# ── 6. Wiring in deploy.sh ──────────────────────────────────────────────────
if bash -n "$DEPLOY_SH" 2>"$TMP/deploy_syntax.log"; then syntax_ok=1; else syntax_ok=0; fi
check "6a deploy.sh is bash-clean" "$syntax_ok"
call_line="$(grep -n 'managed-guards-apply-on-deploy.sh' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
banner_line="$(grep -n 'ПРЕДОХРАНИТЕЛИ ПРОДА РАСХОДЯТСЯ' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
check "6b deploy.sh calls the helper" "$([ -n "$call_line" ] && echo 1 || echo 0)"
check "6c the call sits BEFORE the drift banner (banner = drift survived the apply)" \
  "$([ -n "$call_line" ] && [ -n "$banner_line" ] && [ "$call_line" -lt "$banner_line" ] && echo 1 || echo 0)"
check "6d deploy.sh logs the managed-apply outcome to deploys.log" \
  "$([ "$(grep -c 'MANAGED APPLY' "$DEPLOY_SH")" -ge 2 ] && echo 1 || echo 0)"
check "6e rollback path untouched (--rollback still parsed)" \
  "$(contains "$DEPLOY_SH" '--rollback) shift')"
check "6f fuse contract untouched (exit 0 on drift, issue #1143 comment kept)" \
  "$(contains "$DEPLOY_SH" 'Exit 0 (issue #1143)')"

echo
if [ "$fail" = 0 ]; then
  echo "✔ test_managed_guards_apply_on_deploy: ${pass}/${pass} PASSED — row-9 drift closes inside the deploy, second run is a no-op"
  exit 0
fi
echo "✖ test_managed_guards_apply_on_deploy: ${fail} FAILED, ${pass} passed"
exit 1
