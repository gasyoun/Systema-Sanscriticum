#!/bin/bash
# systema-deadman91-watch.sh — внешний свидетель .91's deadman_check.py (H4929, E002 layer 2).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-deadman91-watch.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. tools/backup_w2/deadman_check.py на .91 — единственный читатель
# ВСЕХ E002 бэкап-лейнов (SENTINELS.md «deadman-check»), но сам он никем не
# читается (H1904: «страж не разделяет судьбу подопечного» — нарушено, если
# .91 или его timer умирает молча, никто не кричит). Начиная с H4929 каждый
# успешный прогон deadman_check.py пушит heartbeat ОФФ-БОКС через
# forced-command ключ (юзер deadman-witness, authorized_keys разрешает
# только `touch` одной метки — не шелл), сюда, на .92:
#   /var/lib/systema-guards/deadman91_hub.last
# Этот сторож — единственный читатель этой метки; тишина = .91's timer
# остановлен ИЛИ весь .91 мёртв (единственный факт, который сам deadman_check
# доказать о себе не может — H4929 mission).
#
# ЧТО ДЕЛАЕТ. Каждый такт (см. root.crontab): читает mtime метки, считает
# возраст.
#   • возраст ≤ @@DEADMAN91_WATCH_STALE_MINUTES@@ мин — GREEN в бриф, exit 0;
#   • возраст больше (или метки нет) — FAIL в бриф, page_or_queue P1 (окно
#     awake решает lane_lib: вне окна страница уходит в утренний бриф), exit 2.
# Дедуп: P1 не чаще раза в @@DEADMAN91_WATCH_PAGE_EVERY_SECONDS@@ с; успешный
# GREEN сбрасывает дедуп, чтобы следующий разрыв снова пейджил.
#
# Код возврата: 0 = heartbeat свежий, 2 = тишина (страница отправлена/задедуплена),
# 1 = операционная ошибка (метка нечитаема/не поддаётся stat).
#
# Тестовые швы: DEADMAN91_MARKER, DEADMAN91_STATE_DIR, BRIEF_DIR, LANE_LIB, DEADMAN91_NOW.
set -uo pipefail

MARKER="${DEADMAN91_MARKER:-/var/lib/systema-guards/deadman91_hub.last}"
STALE_MIN="${DEADMAN91_STALE_MIN:-@@DEADMAN91_WATCH_STALE_MINUTES@@}"
PAGE_EVERY="${DEADMAN91_PAGE_EVERY:-@@DEADMAN91_WATCH_PAGE_EVERY_SECONDS@@}"
STATE_DIR="${DEADMAN91_STATE_DIR:-/var/lib/systema-guards}"
NAME=deadman91_watch
LOG=/var/log/systema-deadman91-watch.log

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

NOW="${DEADMAN91_NOW:-$(date -u +%s)}"

reason=""
age=""
if [ ! -f "$MARKER" ]; then
  reason="метка героя-свидетеля отсутствует ($MARKER) — deadman_check.py на .91 ни разу не пушил"
  age=-1
else
  last=$(stat -c %Y "$MARKER" 2>/dev/null | tr -d '[:space:]')
  case "$last" in
    ''|*[!0-9]*)
      {
        echo ".91 deadman witness .92 $(TS)Z"
        echo "WARN — метка нечитаема (operational): stat не вернул mtime"
      } > "$BRIEF_DIR/${NAME}_latest.md"
      log "WARN operational unreadable marker"
      hb_mark "$NAME" 1
      exit 1
      ;;
  esac
  age=$((NOW - last))
  [ "$age" -lt 0 ] && age=0
  [ "$age" -gt $((STALE_MIN * 60)) ] && reason="heartbeat молчит ${age}s (> $((STALE_MIN * 60))s)"
fi

if [ -z "$reason" ]; then
  {
    echo ".91 deadman witness .92 $(TS)Z"
    echo "GREEN — heartbeat свежий: ${age}s назад (порог $((STALE_MIN * 60))s)"
  } > "$BRIEF_DIR/${NAME}_latest.md"
  rm -f "$PAGEF" 2>/dev/null || true
  log "OK age=${age}s"
  hb_mark "$NAME" 0
  exit 0
fi

# ── Тишина: страница с дедупом ──────────────────────────────────────────────
last_page=0
[ -f "$PAGEF" ] && last_page=$(cat "$PAGEF" 2>/dev/null | tr -d '[:space:]')
case "$last_page" in ''|*[!0-9]*) last_page=0 ;; esac

paged=0
if [ $((NOW - last_page)) -ge "$PAGE_EVERY" ]; then
  page_or_queue "P1" "внешний свидетель .91 (H4929): $reason. Значит либо остановлен systema-deadman.timer на .91, либо .91 целиком умер — сам deadman_check.py об этом сообщить о себе не может. Проверь .91 (ssh root@193.232.229.91), затем: systemctl status systema-deadman.timer."
  printf '%s' "$NOW" > "$PAGEF" 2>/dev/null || true
  paged=1
fi

{
  echo ".91 deadman witness .92 $(TS)Z"
  echo "FAIL — $reason"
  echo "  действие: проверить .91 (ssh root@193.232.229.91), затем"
  echo "  systemctl status systema-deadman.timer; systemctl start systema-deadman.timer при необходимости;"
  echo "  страница: $( [ "$paged" = 1 ] && echo "отправлена/поставлена" || echo "задедуплена" )"
} > "$BRIEF_DIR/${NAME}_latest.md"
log "FAIL $reason paged=$paged"
hb_mark "$NAME" 2
exit 2
