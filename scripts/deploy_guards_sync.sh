#!/usr/bin/env bash
# deploy_guards_sync.sh — H7060: deploy.sh's managed-guard reconcile step.
#
# Row 9 of the 18-10-2026 guard=none incident: a legitimate commit edited a
# managed file, deploy.sh ran guards:verify, printed "drift", and then did
# nothing. The code deploy was green, the drift was permanent, the auto-deploy
# fuse burned and 26 alerts over 36 hours changed no file.
#
# This helper keeps the two rules that made the old behaviour defensible:
#   • every system change a code deploy makes is announced (dry-run diff, then
#     each `changed` line is copied to deploys.log);
#   • guards drift is never allowed to roll a healthy code deploy back (#1143).
# It adds the missing step: apply the managed files, then verify again.
#
# Rollback keeps the old verify-only behaviour: an emergency rollback must not
# rewrite system config. --verify-only does exactly the old guards:verify.
#
# Exit codes: 0 = guards clean (possibly after apply), 75 = drift remains.
# deploy.sh treats 75 as non-fatal (code is live; issue #1143) and keeps the
# loud warning. The fuse and the rollback path in systema-auto-deploy-run.sh are
# untouched.

set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
APP_USER="${APP_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"
APPLY_SH="${SERVER_GUARDS_APPLY_SH:-$APP_DIR/scripts/server_guards_apply.sh}"
DEPLOY_LOG="${DEPLOY_LOG:-$APP_DIR/storage/logs/deploys.log}"
VERIFY_ONLY=0
[ "${1:-}" = "--verify-only" ] && VERIFY_ONLY=1

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m⚠ %s\033[0m\n' "$*"; }
ok() { printf '  \033[1;32mok\033[0m %s\n' "$*"; }

# Same identity as the pre-H7060 deploy line (`php artisan guards:verify`):
# root when deploy.sh runs via cron. The app user cannot read every system file
# the auditor inspects, so switching identity here would invent drift.
run_guard_verify() {
  "$PHP_BIN" "$APP_DIR/artisan" guards:verify
}

say "Предохранители ОС: guards:verify"
if run_guard_verify; then
  ok "guards:verify — расхождений нет"
  exit 0
fi

if [ "$VERIFY_ONLY" = 1 ]; then
  warn "ОТКАТ: guards:verify видит drift, apply не запускаю (rollback path unchanged)"
  exit 75
fi

if [ ! -f "$APPLY_SH" ]; then
  warn "нет $APPLY_SH — применить предохранители нечем"
  exit 75
fi

say "GUARDS DRIFT: dry-run diff (что именно изменит деплой)"
bash "$APPLY_SH" --dry-run --diff || true

say "GUARDS DRIFT: применяю managed-файлы (каждое изменение — в deploys.log)"
APPLY_OUT=$(mktemp)
trap 'rm -f "$APPLY_OUT"' EXIT
bash "$APPLY_SH" 2>&1 | tee "$APPLY_OUT"
apply_rc=${PIPESTATUS[0]}

announced=0
while IFS= read -r line; do
  case "$line" in
    *changed*)
      printf '%s GUARDS APPLY:%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${line#*changed}" >> "$DEPLOY_LOG"
      announced=$((announced + 1))
      ;;
  esac
done < "$APPLY_OUT"
ok "объявлено system-изменений: $announced (deploys.log)"

if [ "$apply_rc" -ne 0 ]; then
  warn "server_guards_apply.sh завершился с кодом $apply_rc — проверяю, что осталось"
fi

say "Предохранители ОС: guards:verify после apply"
if run_guard_verify; then
  ok "guards:verify — дрейф закрыт в этом же деплое"
  exit 0
fi

warn "guards:verify всё ещё недоволен — drift остаётся, код при этом жив (#1143)"
exit 75
