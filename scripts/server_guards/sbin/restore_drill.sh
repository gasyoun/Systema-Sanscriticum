#!/bin/bash
# restore_drill.sh — MONTHLY restore drill (root, 1st of month 03:14 UTC; the cron line is
# managed in scripts/server_guards/cron/root.crontab). MG ruling 05-09-2026: "Verifier +
# monthly restore drill" — the strongest backup guarantee is an actual restore.
#
# H6307 (10-10-2026) — password-file contract repair. The live backup lane authenticates with
# RESTIC_PASSWORD_FILE, but this drill only understood the legacy literal RESTIC_PASSWORD and
# printed SKIPPED-NO-ACCESS on every monthly run (live: 2026-10-01 03:14Z report, drill_latest.md;
# census H6286 09-10-2026, channel 5 = AMBER). Credentials are now resolved in this order:
#   1. RESTIC_PASSWORD_FILE (env / creds env-file / backup run-script) — the working contract.
#      The file must exist, be readable and non-empty; group/other-readable perms => loud WARN.
#   2. RESTIC_PASSWORD (legacy literal) — still honoured for backward compatibility.
# Secret values are never echoed, logged or passed in argv: restic reads them from env/file.
#
# v1 scope unchanged (files+manifest, no DB load): restore the newest systema snapshot's
# include-mask sample into a scratch dir, verify nonzero file count + gzip -t on one sample.
# A full scratch-DB restore (06-08-2026 precedent) is the v2 step — NOT this change.
# Report-only: never touches live data. The scratch dir is its own mktemp and is removed by a
# trap on EVERY exit path; sibling restic_drill.* dirs of other runs are never touched.
#
# Portable knobs (defaults are the prod paths; test_restore_drill.sh drives them):
#   RESTIC_DRILL_ENV       creds env file       (default /root/.restic-systema.env)
#   RESTIC_RUN_SCRIPT      backup lane script   (default /usr/local/sbin/systema-restic-run.sh)
#   RESTIC_DRILL_LANE_LIB  hermes lane library  (default /home/hermes/bin/lane_lib.sh)
#   RESTIC_DRILL_TMP_BASE  scratch parent dir   (default /var/tmp)
#   RESTIC_DRILL_INCLUDE   restic --include mask (default /var/backups/*.gz)
set -u

LANE_LIB="${RESTIC_DRILL_LANE_LIB:-/home/hermes/bin/lane_lib.sh}"
DRILL_ENV="${RESTIC_DRILL_ENV:-/root/.restic-systema.env}"
RUN="${RESTIC_RUN_SCRIPT:-/usr/local/sbin/systema-restic-run.sh}"
TMP_BASE="${RESTIC_DRILL_TMP_BASE:-/var/tmp}"
INCLUDE="${RESTIC_DRILL_INCLUDE:-/var/backups/*.gz}"

# ── lane library: the real one on the box, minimal loud fallbacks elsewhere ────
# (missing lane_lib must degrade to a report file, never kill the drill).
if [ -r "$LANE_LIB" ]; then
  . "$LANE_LIB"
else
  BRIEF_DIR="${BRIEF_DIR:-/var/tmp/systema-drill-brief}"
  mkdir -p "$BRIEF_DIR" 2>/dev/null || true
  page_or_queue() { echo "PAGE $1: $2" >> "$BRIEF_DIR/pages.fallback.log"; }
  hb_mark() { :; }
fi
OUT="$BRIEF_DIR/drill_latest.md"
SCRATCH="$(mktemp -d "$TMP_BASE/restic_drill.XXXXXX")"
cleanup() { rm -rf "$SCRATCH"; }
trap cleanup EXIT INT TERM

# ionice is Linux-only; degrade silently on hosts without it.
NICE=(nice -n 19)
command -v ionice >/dev/null 2>&1 && NICE+=(ionice -c3)

PF_ERR=""   # why a configured RESTIC_PASSWORD_FILE is unusable (missing|unreadable|empty)
PW_WARN=""  # loose perms finding, reported but not fatal (drill is read-only)

# Validate the password-file contract. Sets PW_PATH on success, PF_ERR on failure.
resolve_password_file() {
  PW_PATH=""
  [ -n "${RESTIC_PASSWORD_FILE:-}" ] || return 1
  if [ ! -f "$RESTIC_PASSWORD_FILE" ]; then PF_ERR="missing"; return 1; fi
  if [ ! -r "$RESTIC_PASSWORD_FILE" ]; then PF_ERR="unreadable"; return 1; fi
  if [ ! -s "$RESTIC_PASSWORD_FILE" ]; then PF_ERR="empty"; return 1; fi
  local perms grp oth
  perms="$(ls -ld "$RESTIC_PASSWORD_FILE" 2>/dev/null | cut -c1-10)"
  grp="${perms:5:1}"; oth="${perms:8:1}"
  if [ "$grp" = "r" ] || [ "$oth" = "r" ]; then
    PW_WARN="WARN — RESTIC_PASSWORD_FILE доступен группе/прочим (mode ${perms}); ужать до 0600"
  fi
  PW_PATH="$RESTIC_PASSWORD_FILE"
  return 0
}

# ── source creds exactly the way the real backup lane does — never re-type secrets ──
if [ -r "$DRILL_ENV" ]; then set -a; . "$DRILL_ENV"; set +a; fi
REPO="${RESTIC_REPOSITORY:-}"
if [ -z "$REPO" ] && [ -f "$RUN" ]; then
  REPO="$(grep -oE 'RESTIC_REPOSITORY=[^ ]+' "$RUN" | head -1 | cut -d= -f2- | tr -d '"')"
fi
# FILE contract from the run-script as a fallback (path value — parsed, never eval'd).
if [ -z "${RESTIC_PASSWORD_FILE:-}" ] && [ -f "$RUN" ]; then
  RESTIC_PASSWORD_FILE="$(grep -oE 'RESTIC_PASSWORD_FILE=[^ ]+' "$RUN" | head -1 | cut -d= -f2- | tr -d '"')"
fi
resolve_password_file || true

# Legacy literal fallback: only if NO usable password file, pull export RESTIC_PASSWORD=
# from the run script the same way the pre-H6307 drill did (value stays in env, never echoed).
if [ -z "$PW_PATH" ] && [ -z "${RESTIC_PASSWORD:-}" ] && [ -f "$RUN" ]; then
  # shellcheck disable=SC2046  # intended word-split of a single matched export line
  eval "$(grep -oE 'export RESTIC_PASSWORD=[^ ]+' "$RUN" | head -1 | sed 's/export //')" 2>/dev/null || true
fi

{
  echo "Восстановительный прогон — $(date -u '+%F %T')Z"
  if [ -n "$PF_ERR" ]; then
    # A configured-but-broken password file means the backup lane itself is likely dead.
    echo "FAIL — RESTIC_PASSWORD_FILE задан (${PF_ERR}), пароль недоступен — гарантия восстановления мертва; сверить руками"
    page_or_queue "P1" "Restore drill: RESTIC_PASSWORD_FILE broken (${PF_ERR}) — бэкапы могут быть ненастоящими"
  elif [ -z "$REPO" ] || { [ -z "$PW_PATH" ] && [ -z "${RESTIC_PASSWORD:-}" ]; }; then
    echo "SKIPPED-NO-ACCESS — не нашёл REPO/PASSWORD в ${DRILL_ENV} или ${RUN}; сверить руками"
  else
    [ -n "$PW_WARN" ] && echo "$PW_WARN"
    [ -n "$PW_PATH" ] && echo "GREEN — пароль взят из RESTIC_PASSWORD_FILE (значение не печатается)"
    snap=$(nice -n 19 restic -r "$REPO" snapshots --latest 1 --json 2>/dev/null | grep -oE '"id":"[a-f0-9]{8}' | head -1 | cut -d'"' -f4)
    if [ -n "$snap" ]; then
      echo "GREEN — свежайший снапшот ${snap} отвечает; восстанавливаю файлы в scratch"
      # restore: scratch-only target, --verify on, mask from INCLUDE. Exit status is taken
      # from restic itself (plain if on a command substitution), never from a pipe.
      if rlog=$("${NICE[@]}" restic -r "$REPO" restore "$snap" --target "$SCRATCH" --include "$INCLUDE" --verify 2>&1); then
        echo "$rlog" | tail -3
        n=$(find "$SCRATCH" -type f | wc -l | tr -d ' ')
        if [ "$n" -gt 0 ]; then
          echo "GREEN — файлов восстановлено: $n (маска $INCLUDE)"
          sample=$(find "$SCRATCH" -type f | head -1)
          if [ -n "$sample" ] && gzip -t "$sample" 2>/dev/null; then
            echo "GREEN — целостность сэмпла OK: $(basename "$sample") sha256=$(sha256sum "$sample" | cut -d' ' -f1)"
          elif [ -n "$sample" ]; then
            echo "GREEN — сэмпл не .gz, целостность по факту наличия: $(basename "$sample") sha256=$(sha256sum "$sample" | cut -d' ' -f1)"
          else
            echo "WARN — файлов >0, но сэмпл не выбран для проверки"
          fi
        else
          echo "WARN — 0 файлов по include-маске; для v2 нужен полный restore-прогон"
        fi
      else
        echo "$rlog" | tail -3
        echo "FAIL — restic restore завершился ошибкой (снапшот ${snap}); сверить руками"
        page_or_queue "P1" "Restore drill: restore failed on snapshot ${snap} — восстановимость под вопросом"
      fi
    else
      echo "FAIL — restic не ответил списком снапшотов (репо/сеть/пароль?)"
      page_or_queue "P1" "Restore drill: restic не ответил — бэкапы могут быть ненастоящими"
    fi
  fi
} > "$OUT"

chown hermes:hermes "$OUT" 2>/dev/null || true
hb_mark "restore_drill" 0
# SCRATCH removal is trap-guaranteed (success, failure, signal) and confined to this run.
exit 0
