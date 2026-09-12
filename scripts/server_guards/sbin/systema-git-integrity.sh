#!/bin/bash
# systema-git-integrity.sh — ежедневная проверка ЦЕЛОСТНОСТИ git-дерева прода (H4590).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-git-integrity.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. /var/www/html — это git working tree самого этого репозитория, но
# деплой идёт на сервер, а значит у дерева ЕСТЬ незакоммиченная грязь, которую
# никто не отличал от чужого файла. Урок samskrtam95 (H4570, docs/
# RESULTS_SAMSKRTAM95_HIJACK_RESTORE_QUARANTINE_11-09-2026.md): импланты жили
# именно в незакоммиченных файлах докорня — `fast-home.php`, `.user.ini`,
# `wp-blog-header.php` с дорожкой дроппера. Никакой мониторинг не смотрел на
# дельту дерева.
#
# ЧТО ДЕЛАЕТ. Раз в сутки: `git status --porcelain` + `git diff HEAD` по
# РАЗВЁРНУТОМУ дереву. Tracked-изменения (M/D/A/R у существующих файлов) —
# подозрение ВСЕГДА. Untracked-файлы сверяются с базисом «законно грязного»
# (expected-dirty.conf): что не совпало ни с одной строкой — подозрение.
# Отчёт-only: ничего не чинит и не удаляет, пишет строку в Hermes-дайджест
# (brief/git_integrity_latest.md) и завершается НЕнулём при подозрении.
#
# Код возврата: 0 = чисто, 2 = tamper suspect, 1 = операционная ошибка
# (нет базиса / git не отработал) — по образцу samskrtam_tamper_watch.py.
set -uo pipefail

APP_DIR="@@APP_DIR@@"
BASELINE="@@GIT_BASELINE_FILE@@"
LOG=/var/log/systema-git-integrity.log
NAME=git_integrity
MAX_SHOWN=20

# lane_lib несёт BRIEF_DIR/page_or_queue/hb_mark. Если её нет (чужой бокс) —
# деградируем до лога, но НЕ молча: строка в журнал об этом честно говорит.
if [ -r /home/hermes/bin/lane_lib.sh ]; then
  # shellcheck source=/dev/null
  . /home/hermes/bin/lane_lib.sh
else
  BRIEF_DIR=/home/hermes/brief; mkdir -p "$BRIEF_DIR" 2>/dev/null || true
  hb_mark() { :; }
  page_or_queue() { echo "$(date -u '+%F %T') [$1] $2" >> "$BRIEF_DIR/pending_pages.txt" 2>/dev/null || true; }
fi

TS() { date -u '+%F %T'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG"; }

brief() { printf '%s\n' "$@" > "$BRIEF_DIR/${NAME}_latest.md"; }
finish() { # finish <rc>
  hb_mark "$NAME" "$1"
  log "exit=$1"
  exit "$1"
}

cd "$APP_DIR" 2>/dev/null || {
  brief "Git-целостность $(TS)Z" "FAIL — APP_DIR=$APP_DIR недоступен"
  log "FAIL app-dir-missing $APP_DIR"
  page_or_queue "P1" "git-integrity: APP_DIR недоступен на .92"
  finish 1
}

# safe.directory: /var/www/html принадлежит www-data, а guard ходит от root —
# без этого git отвечает «dubious ownership» и проверка слепнет.
GIT=(git -c "safe.directory=$APP_DIR")

if ! "${GIT[@]}" rev-parse --git-dir >/dev/null 2>&1; then
  brief "Git-целостность $(TS)Z" "FAIL — $APP_DIR не git-дерево (слепая проверка)"
  log "FAIL not-a-git-tree"
  page_or_queue "P1" "git-integrity: /var/www/html не git-дерево на .92"
  finish 1
fi

# ── Базис ────────────────────────────────────────────────────────────────────
if [ ! -r "$BASELINE" ]; then
  brief "Git-целостность $(TS)Z" "FAIL — базис $BASELINE не читается (проверять нечем)"
  log "FAIL baseline-missing $BASELINE"
  page_or_queue "P2" "git-integrity: базис expected-dirty.conf не установлен на .92"
  finish 1
fi
mapfile -t PATTERNS < <(grep -vE '^[[:space:]]*(#|$)' "$BASELINE" 2>/dev/null)

is_expected() { # is_expected <untracked-path>
  local p="$1" pat
  for pat in "${PATTERNS[@]}"; do
    [ -z "$pat" ] && continue
    # p — данные (LHS), pat — regex (RHS): метасимволы в имени файла не ломают матч.
    [[ "$p" =~ $pat ]] && return 0
  done
  return 1
}

# ── Разбор статуса ───────────────────────────────────────────────────────────
# -z + null-разделитель: имена с пробелами и метасимволами (у нас в корне живут
# файлы `[^,]`, `value`, `nul`) не рвут разбор. У переименований porcelain -z
# кладёт ИСХОДНЫЙ путь СЛЕДУЮЩИМ полем — его надо съесть, иначе оно станет
# фантомной записью.
#
# Вывод пишется в файл, а не в процесс-подстановку: НЕпроверенный код возврата
# `git status` при пустом выводе читался бы как «GREEN — 0 грязи», то есть
# транзиентный сбой (лок индекса в момент */30 деплоя) давал бы слепую зелень
# вопреки контракту (finding независимой верификации H4590, 11-09-2026).
STATUS_TMP=$(mktemp) || {
  brief "Git-целостность $(TS)Z" "FAIL — mktemp недоступен (проверка невозможна)"
  log "FAIL mktemp-failed"
  page_or_queue "P2" "git-integrity: mktemp недоступен на .92"
  finish 1
}
trap 'rm -f "$STATUS_TMP"' EXIT

"${GIT[@]}" status --porcelain=v1 -z --untracked-files=all > "$STATUS_TMP" 2>/dev/null
STATUS_RC=$?
if [ "$STATUS_RC" -ne 0 ]; then
  brief "Git-целостность $(TS)Z" "FAIL — git status не отработал (слепая проверка — НЕ «чисто»)"
  log "FAIL git-status-failed rc=$STATUS_RC"
  page_or_queue "P2" "git-integrity: git status не отработал на .92 — проверка слепа"
  finish 1
fi

TRACKED=(); UNTRACKED=()
while IFS= read -r -d '' rec; do
  st="${rec:0:2}"; path="${rec:3}"
  case "$st" in
    R*|C*) IFS= read -r -d '' _orig || true ;;
  esac
  if [ "$st" = "??" ]; then UNTRACKED+=("$path"); else TRACKED+=("$st $path"); fi
done < "$STATUS_TMP"

SUSPECTS=()
for t in "${TRACKED[@]}"; do SUSPECTS+=("$t"); done
for u in "${UNTRACKED[@]}"; do
  if ! is_expected "$u"; then SUSPECTS+=("?? $u"); fi
done

# git diff HEAD по tracked — второй свидетель первой проверки (porcelain ловит
# и staged, diff показывает объём). Ошибка diff = операционная, не «чисто».
DIFF_LINES=$("${GIT[@]}" diff HEAD --stat 2>/dev/null | tail -1)
DIFF_RC=$?
if [ "$DIFF_RC" -ne 0 ]; then
  brief "Git-целостность $(TS)Z" "FAIL — git diff не отработал (проверка tracked-дельты слепа)"
  log "FAIL git-diff-failed rc=$DIFF_RC"
  page_or_queue "P2" "git-integrity: git diff не отработал на .92 — проверка tracked-дельты слепа"
  finish 1
fi
[ -z "$DIFF_LINES" ] && DIFF_LINES="(пусто)"

# ── Вердикт ──────────────────────────────────────────────────────────────────
{
  echo "Git-целостность /var/www/html $(TS)Z"
  if [ "${#SUSPECTS[@]}" -eq 0 ]; then
    echo "GREEN — tracked-dirty 0, untracked ${#UNTRACKED[@]} (все в базисе), diff: ${DIFF_LINES}"
  else
    echo "FAIL — tamper suspect: ${#SUSPECTS[@]} (tracked ${#TRACKED[@]}, untracked-вне-базиса)"
    n=0
    for s in "${SUSPECTS[@]}"; do
      n=$((n + 1)); [ "$n" -gt "$MAX_SHOWN" ] && { echo "  … +$(( ${#SUSPECTS[@]} - MAX_SHOWN )) ещё"; break; }
      echo "  • $s"
    done
    echo "  базис: $BASELINE (${#PATTERNS[@]} patterns) — правка кода только через main"
  fi
} > "$BRIEF_DIR/${NAME}_latest.md"

if [ "${#SUSPECTS[@]}" -gt 0 ]; then
  log "FAIL suspects=${#SUSPECTS[@]} tracked=${#TRACKED[@]} untracked=${#UNTRACKED[@]}"
  page_or_queue "P1" "git-integrity .92: ${#SUSPECTS[@]} tamper suspect в /var/www/html (см. brief/git_integrity_latest.md)"
  finish 2
fi

log "OK untracked=${#UNTRACKED[@]} tracked=0"
finish 0
