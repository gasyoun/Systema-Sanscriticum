#!/bin/bash
# systema-ib-balance-watch.sh — серверный сторож пульса ib-balance-вотчера (MG 14-09-2026).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-ib-balance-watch.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. opencode на рабочем боксе circuit-break'ает провайдера на ~4 часа при
# ложной ошибке «Insufficient Balance» (state/provider-health.json, deadUntil) —
# задачи встают молча, хотя баланс в порядке. На боксе стоит задача Планировщика
# (claude ib-balance sweep), которая каждые ~1 мин снимает карантин и пульсирует
# меткой /tmp/ib_sweep_last_clear (epoch). Сервер файл карантина править не может
# — у него честная роль СТОРОЖА: молчание пульса = вотчер умер или бокс выключен.
#
# ЧТО ДЕЛАЕТ. Каждый такт (см. root.crontab): читает метку, считает возраст.
#   • возраст ≤ @@IB_BALANCE_WATCH_STALE_MINUTES@@ мин — GREEN в бриф, exit 0;
#   • возраст больше (или метки нет) — FAIL в бриф, page_or_queue P1 (окно
#     awake решает lane_lib: вне окна страница уходит в утренний бриф), exit 2.
# Дедуп: P1 не чаще раза в @@IB_BALANCE_WATCH_PAGE_EVERY_SECONDS@@ с; успешный
# GREEN сбрасывает дедуп, чтобы следующий разрыв снова пейджил.
# Бокс выключен ночью — это штатная тишина: P1 вне окна не пейджит, а копится
# в brief/pending_pages.txt к утреннему дайджесту.
#
# Код возврата: 0 = пульс свежий, 2 = тишина (страница отправлена/задедуплена),
# 1 = операционная ошибка (метка нечитаема/не число).
#
# Тестовые швы: IB_MARKER, IB_STATE_DIR, BRIEF_DIR, LANE_LIB, IB_NOW.
set -uo pipefail

MARKER="${IB_MARKER:-/tmp/ib_sweep_last_clear}"
STALE_MIN="${IB_STALE_MIN:-@@IB_BALANCE_WATCH_STALE_MINUTES@@}"
PAGE_EVERY="${IB_PAGE_EVERY:-@@IB_BALANCE_WATCH_PAGE_EVERY_SECONDS@@}"
STATE_DIR="${IB_STATE_DIR:-/var/lib/systema-guards}"
NAME=ib_balance_watch
LOG=/var/log/systema-ib-balance-watch.log

LANE_LIB="${LANE_LIB:-/home/hermes/bin/lane_lib.sh}"
if [ -r "$LANE_LIB" ]; then
  # shellcheck source=/dev/null
  . "$LANE_LIB"
else
  BRIEF_DIR="${BRIEF_DIR:-/home/hermes/brief}"; mkdir -p "$BRIEF_DIR" 2>/dev/null || true
  hb_mark() { :; }
  page_or_queue() { echo "$(date -u '+%F %T') [$1] $2" >> "$BRIEF_DIR/pending_pages.txt" 2>/dev/null || true; }
fi
BRIEF_DIR="${BRIEF_DIR:-/home/hermes/brief}"

TS() { date -u '+%F %T'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG" 2>/dev/null || true; }

mkdir -p "$STATE_DIR" 2>/dev/null || true
PAGEF="$STATE_DIR/${NAME}.last_page"

NOW="${IB_NOW:-$(date -u +%s)}"

reason=""
age=""
if [ ! -f "$MARKER" ]; then
  reason="метка пульса отсутствует ($MARKER) — вотчер не запускался или бокс выключен"
  age=-1
else
  last=$(cat "$MARKER" 2>/dev/null | tr -d '[:space:]')
  case "$last" in
    ''|*[!0-9]*)
      {
        echo "ib-balance watch .92 $(TS)Z"
        echo "WARN — метка нечитаема (operational): содержимое не epoch-число"
      } > "$BRIEF_DIR/${NAME}_latest.md"
      log "WARN operational unreadable marker"
      hb_mark "$NAME" 1
      exit 1
      ;;
  esac
  age=$((NOW - last))
  [ "$age" -lt 0 ] && age=0
  [ "$age" -gt $((STALE_MIN * 60)) ] && reason="пульс молчит ${age}s (> $((STALE_MIN * 60))s)"
fi

if [ -z "$reason" ]; then
  {
    echo "ib-balance watch .92 $(TS)Z"
    echo "GREEN — пульс свежий: ${age}s назад (порог $((STALE_MIN * 60))s)"
  } > "$BRIEF_DIR/${NAME}_latest.md"
  rm -f "$PAGEF" 2>/dev/null || true
  log "OK age=${age}s"
  hb_mark "$NAME" 0
  exit 0
fi

# ── Тишина: страница с часовым дедупом ──────────────────────────────────────
last_page=0
[ -f "$PAGEF" ] && last_page=$(cat "$PAGEF" 2>/dev/null | tr -d '[:space:]')
case "$last_page" in ''|*[!0-9]*) last_page=0 ;; esac

paged=0
if [ $((NOW - last_page)) -ge "$PAGE_EVERY" ]; then
  page_or_queue "P1" "ib-balance вотчер на боксе молчит: $reason. Карантин провайдера не снимается — задачи встанут молча. Проверь бокс/задачу «claude ib-balance sweep» (лог ~/.claude/state/ib_autoresume.log)."
  printf '%s' "$NOW" > "$PAGEF" 2>/dev/null || true
  paged=1
fi

{
  echo "ib-balance watch .92 $(TS)Z"
  echo "FAIL — $reason"
  echo "  действие: проверить бокс (включён?) и задачу «claude ib-balance sweep»;"
  echo "  лог бокса: %USERPROFILE%\\.claude\\state\\ib_autoresume.log;"
  echo "  переустановка пульса: schtasks /Run /TN \"claude ib-balance sweep\""
  echo "  страница: $( [ "$paged" = 1 ] && echo "отправлена/поставлена" || echo "задедуплена (≤1/час)" )"
} > "$BRIEF_DIR/${NAME}_latest.md"
log "FAIL $reason paged=$paged"
hb_mark "$NAME" 2
exit 2
