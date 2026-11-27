#!/bin/bash
# systema-socks91-watch.sh — внешний свидетель SOCKS-канала .91 (H8564, Systema #4429 п.2).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-socks91-watch.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. socks-liveness-check.sh на .91 (таймер 5 мин) уже ловит «туннель
# заклинило при живой машине»: он делает настоящий SOCKS CONNECT и кричит через
# .92. Чего он доказать о СЕБЕ не может — что сам он ещё ходит. Остановленный
# таймер, мёртвый .91 и сломанный `ssh -D` дают одну и ту же картину: тишину.
# Поэтому каждый УСПЕШНЫЙ прогон socks-liveness-check.sh пушит метку ОФФ-БОКС
# через forced-command ключ (юзер socks91-witness, authorized_keys разрешает
# ровно `touch` одной метки — не шелл):
#   /var/lib/systema-guards/socks91_hub.last
# Этот сторож — единственный читатель этой метки. Тишина = .91 не пушит.
#
# КАК КРИЧИТ. Только канонический канал «Вестник» (H4686, эпик E002 C8):
#   /usr/local/bin/uprava_alert → @samskrte_bot → «Отдел заботы» (-1002079934542)
# НЕ hermes_notify.sh (тот шлёт только MG) и не локальный tg_send из lane_lib.
# Рулинг MG 23-11-2026: всё, что адресовано Ивану, идёт в «Отдел заботы».
# Отказ доставки громкий: строка в pending_pages.txt + exit 1, молчания нет.
#
# ЧТО ДЕЛАЕТ. Каждый такт (systema-socks91-watch.timer, 5 мин):
#   • возраст метки ≤ 15 мин — GREEN в бриф, exit 0;
#   • больше (или метки нет) — FAIL в бриф, страница с дедупом ≥3600 с, exit 2.
# Дедуп: страница не чаще раза в 3600 с на эпизод; успешный GREEN сбрасывает
# дедуп, чтобы следующий разрыв снова пейджил. Здоровый рестарт туннеля
# (~секунды) метку не рвёт: порог 900 с на порядок больше рестарта.
#
# Коды возврата: 0 = метка свежая, 2 = тишина (страница отправлена/задедуплена),
# 1 = операционная ошибка (метка нечитаема, канал доставки отказал).
#
# Тестовые швы: SOCKS91_MARKER, SOCKS91_STATE_DIR, SOCKS91_STALE_MIN,
# SOCKS91_PAGE_EVERY, SOCKS91_NOW, SOCKS91_ALERT_BIN, BRIEF_DIR, LANE_LIB.
#
# Связанные: scripts/server_guards_n8n/sbin/socks-liveness-check.sh (.91, писатель
# метки), /usr/local/sbin/socks-alarm-relay.sh (.92, приёмник крика .91),
# docs/SOCKS91_EXTERNAL_WITNESS.md (развёртывание, приёмка, откат).
set -uo pipefail

MARKER="${SOCKS91_MARKER:-/var/lib/systema-guards/socks91_hub.last}"
STALE_MIN="${SOCKS91_STALE_MIN:-15}"
PAGE_EVERY="${SOCKS91_PAGE_EVERY:-3600}"
STATE_DIR="${SOCKS91_STATE_DIR:-/var/lib/systema-guards}"
ALERT_BIN="${SOCKS91_ALERT_BIN:-/usr/local/bin/uprava_alert}"
NAME=socks91_watch
LOG=/var/log/systema-socks91-watch.log

LANE_LIB="${LANE_LIB:-/home/hermes/bin/lane_lib.sh}"
if [ -r "$LANE_LIB" ]; then
  # shellcheck source=/dev/null
  . "$LANE_LIB"
else
  BRIEF_DIR="${BRIEF_DIR:-/home/hermes/brief}"; mkdir -p "$BRIEF_DIR" 2>/dev/null || true
  hb_mark() { :; }
fi
BRIEF_DIR="${BRIEF_DIR:-/home/hermes/brief}"

TS() { date -u '+%F %T'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG" 2>/dev/null || true; }

mkdir -p "$STATE_DIR" "$BRIEF_DIR" 2>/dev/null || true
PAGEF="$STATE_DIR/${NAME}.last_page"
BRIEF="$BRIEF_DIR/${NAME}_latest.md"
PENDING="$BRIEF_DIR/pending_pages.txt"

NOW="${SOCKS91_NOW:-$(date -u +%s)}"

# send_page <text> — доставка через канонический «Вестник». Возвращает 0 только
# на настоящей доставке: uprava_alert по контракту H4686 fail-LOUD (exit≠0).
send_page() {
  local text="$1" out rc
  if [ ! -x "$ALERT_BIN" ]; then
    log "ALARM SKIP: нет $ALERT_BIN (канал «Вестник» не развёрнут на .92)"
    return 78
  fi
  out=$("$ALERT_BIN" "$text" 2>&1); rc=$?
  log "ALARM rc=$rc $(printf '%s' "$out" | tail -n 1 | cut -c1-160)"
  return "$rc"
}

reason=""
age=""
if [ ! -f "$MARKER" ]; then
  reason="метки свидетеля нет ($MARKER) — socks-liveness-check.sh на .91 ни разу не пушил"
  age=-1
else
  last=$(stat -c %Y "$MARKER" 2>/dev/null | tr -d '[:space:]')
  case "$last" in
    ''|*[!0-9]*)
      {
        echo ".91 socks91 witness .92 $(TS)Z"
        echo "WARN — метка нечитаема (operational): stat не вернул mtime"
      } > "$BRIEF"
      log "WARN operational unreadable marker"
      hb_mark "$NAME" 1
      exit 1
      ;;
  esac
  age=$((NOW - last))
  [ "$age" -lt 0 ] && age=0
  [ "$age" -gt $((STALE_MIN * 60)) ] && reason="метка молчит ${age}s (> $((STALE_MIN * 60))s)"
fi

if [ -z "$reason" ]; then
  {
    echo ".91 socks91 witness .92 $(TS)Z"
    echo "GREEN — SOCKS-метка свежая: ${age}s назад (порог $((STALE_MIN * 60))s)"
  } > "$BRIEF"
  rm -f "$PAGEF" 2>/dev/null || true
  log "OK age=${age}s"
  hb_mark "$NAME" 0
  exit 0
fi

# ── Тишина: страница с дедупом ──────────────────────────────────────────────
last_page=0
[ -f "$PAGEF" ] && last_page=$(cat "$PAGEF" 2>/dev/null | tr -d '[:space:]')
case "$last_page" in ''|*[!0-9]*) last_page=0 ;; esac

if [ "$last_page" -gt 0 ] && [ $((NOW - last_page)) -lt "$PAGE_EVERY" ]; then
  {
    echo ".91 socks91 witness .92 $(TS)Z"
    echo "FAIL — $reason"
    echo "страница задедуплена: предыдущая $((NOW - last_page))s назад (окно ${PAGE_EVERY}s)"
  } > "$BRIEF"
  log "SILENT dedup age=${age}s last_page=$((NOW - last_page))s"
  hb_mark "$NAME" 0
  exit 2
fi

msg="🚨 [.91 socks-nl] SOCKS-метка молчит: $reason. Туннель 127.0.0.1:1080 (exit 103.112.71.201) либо таймер socks-liveness на .91, либо сама .91. Incident: Systema #4429"
echo "$NOW" > "$PAGEF.tmp" 2>/dev/null || true
if send_page "$msg"; then
  mv -f "$PAGEF.tmp" "$PAGEF" 2>/dev/null || true
  {
    echo ".91 socks91 witness .92 $(TS)Z"
    echo "FAIL — $reason"
    echo "СТРАНИЦА ОТПРАВЛЕНА в «Отдел заботы» (uprava_alert)"
  } > "$BRIEF"
  log "PAGED age=${age}s"
  hb_mark "$NAME" 0
  exit 2
fi

rm -f "$PAGEF.tmp" 2>/dev/null || true
{
  echo ".91 socks91 witness .92 $(TS)Z"
  echo "FAIL — $reason"
  echo "ДОСТАВКА НЕ УДАЛАСЬ: см. $LOG и /home/hermes/brief/pending_pages.txt"
} > "$BRIEF"
printf '%s [P1] .91 socks91 метка молчит (%s) — доставка «Вестника» отказала\n' "$(TS)" "$reason" >> "$PENDING" 2>/dev/null || true
log "PAGE FAILED age=${age}s"
hb_mark "$NAME" 1
exit 1
