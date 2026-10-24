#!/usr/bin/env bash
# managed-guards-apply-on-deploy.sh — H7060: turn guards drift from a permanent
# warning into a self-healing, ANNOUNCED step of the deploy itself.
#
# The defect this closes (incident row 9, 18-10-2026): deploy.sh ran
# `guards:verify`, printed "ПРЕДОХРАНИТЕЛИ ПРОДА РАСХОДЯТСЯ С РЕПОЗИТОРИЕМ" and
# exited 0 (#1143 — drift must not roll back a good commit). It never called
# server_guards_apply.sh, so a legitimate commit that edited a managed file left
# drift behind forever: the alert stayed, 26 messages over 36 hours fixed
# nothing, and the next human read a scary banner for a fix one command away.
#
# Contract of this step (called by deploy.sh only when guards:verify reported
# drift):
#   1. ANNOUNCE — print the dry-run unified diff of every managed file that
#      disagrees with the repo, so the deploy log carries every system change it
#      is about to make. Nothing is applied before this block is printed.
#   2. APPLY — run the same sanctioned server_guards_apply.sh (idempotent by its
#      own contract), so a second deploy in a row changes nothing.
#   3. VERIFY — server_guards_apply.sh already ends on guards:verify; its exit
#      code is the verdict. Drift that survives an apply stays loud and returns
#      non-zero, so deploy.sh keeps today's banner behaviour.
#
# Exit codes (deploy.sh treats any non-zero as "drift remains", never as a
# failed deploy — issue #1143):
#   0  conform: nothing to do, or applied and verified clean
#   3  applied but drift persists / apply failed
#   4  drift found but this process is not root (apply refused, diff announced)
#   5  server_guards_apply.sh missing on this tree
#
# Usage:
#   bash scripts/server_guards/sbin/managed-guards-apply-on-deploy.sh
#   SERVER_GUARDS_PROFILE=n8n bash ...            # .91 profile passthrough
#   SERVER_GUARDS_APPLY_SH=/path/to/apply.sh ...  # test seam
#   MANAGED_APPLY_UID_OVERRIDE=1000 bash ...      # test seam: pretend non-root
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPLY_SH="${SERVER_GUARDS_APPLY_SH:-$SCRIPT_DIR/../../server_guards_apply.sh}"

# Bash-3.2-safe (macOS /bin/bash): an empty array trips `set -u` as "${A[@]}:
# unbound variable", so the profile passthrough is a plain string.
PROFILE_ARGS=""
if [ -n "${SERVER_GUARDS_PROFILE:-}" ]; then
  PROFILE_ARGS="--profile $SERVER_GUARDS_PROFILE"
fi

say()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m⚠ %s\033[0m\n' "$*"; }
bad()  { printf '\033[1;31m✖ %s\033[0m\n' "$*"; }

uid_now() { printf '%s' "${MANAGED_APPLY_UID_OVERRIDE:-$(id -u)}"; }

if [ ! -f "$APPLY_SH" ]; then
  bad "managed-guards: нет $APPLY_SH — применить нечем, drift остаётся видимым"
  exit 5
fi

# ── 1. Announce: the dry-run diff, before anything is touched ────────────────
say "Managed guards: dry-run diff (announce, nothing applied yet)"
dry_out="$(bash "$APPLY_SH" --dry-run --diff $PROFILE_ARGS 2>&1)"
dry_rc=$?
printf '%s\n' "$dry_out"
if [ "$dry_rc" != 0 ]; then
  bad "managed-guards: dry-run завершился rc=${dry_rc} — apply не запускаю"
  exit 3
fi

dry_changed="$(printf '%s\n' "$dry_out" \
  | sed -n 's/.*dry-run: \([0-9][0-9]*\) файлов разошлись.*/\1/p' | tail -1)"
dry_changed="${dry_changed:-0}"

if [ "$dry_changed" = 0 ]; then
  say "Managed guards: машина уже совпадает с репозиторием — apply не нужен (no-op)"
  exit 0
fi

# ── 2. Apply (root only) ────────────────────────────────────────────────────
if [ "$(uid_now)" != 0 ]; then
  warn "managed-guards: ${dry_changed} файл(ов) расходятся, но процесс не root — apply не запускаю"
  printf '  Вернуть вручную: sudo bash %s\n' "${APPLY_SH#$(pwd)/}"
  exit 4
fi

say "Managed guards: apply ${dry_changed} файл(ов) (server_guards_apply.sh)"
apply_out="$(bash "$APPLY_SH" $PROFILE_ARGS 2>&1)"
apply_rc=$?
printf '%s\n' "$apply_out"

if [ "$apply_rc" != 0 ]; then
  bad "managed-guards: apply rc=${apply_rc} — drift остался, баннер деплоя сохраняется"
  exit 3
fi

applied="$(printf '%s\n' "$apply_out" \
  | sed -n 's/.*(\([0-9][0-9]*\) изменений в этот прогон.*/\1/p' | tail -1)"

say "Managed guards: применено ${applied:-?} изменени(й), guards:verify чист — drift закрыт этим деплоем"
exit 0
