#!/bin/bash
# systema-tamper-watch.sh — ежедневный tamper-watch прода samskrte.ru (H4590).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-tamper-watch.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. Урок samskrtam95 (H4570): 25-секундный TTFB жил на проде НЕДЕЛЯМИ,
# потому что «медленно» никто не мерил; смена публичного контента и ротация
# TLS-сертификата тоже не имели наблюдателя. Отчёт-only пробник внешнего
# облика сайта: раз в сутки ловит (1) подмену/пропажу главной, (2) смерть
# глубокого маршрута, (3) волну тормозов (TTFB), (4) близкий конец
# сертификата, (5) переполнение диска.
#
# ЧТО ДЕЛАЕТ. curl по публичному URL: 200 + маркер заголовка главной + TTFB;
# глубокий маршрут (200 + TTFB); дни до конца TLS-сертификата; процент диска.
# Каждая проба — строка GREEN/FAIL в Hermes-дайджест
# (brief/tamper_watch_latest.md). FAIL — page_or_queue P1 и exit 2.
# Отчёт-only: ничего не чинит.
#
# Код возврата: 0 = все пробы зелёные, 2 = хотя бы одна FAIL, 1 = операционная
# ошибка самого скрипта (по образцу samskrtam_tamper_watch.py).
# --test-url URL — ОФФЛАЙН down-test (приёмка H4590): подменяет HOME_URL и
# помечает прогон SELFTEST в брифе; в бою не используется.
set -uo pipefail

HOME_URL="@@TAMPER_HOME_URL@@"
DEEP_URL="@@TAMPER_DEEP_URL@@"
TITLE="@@TAMPER_HOME_TITLE@@"
CERT_HOST="@@TAMPER_CERT_HOST@@"
CERT_PORT="@@TAMPER_CERT_PORT@@"
TTFB_WARN="@@TAMPER_TTFB_WARN_S@@"
TTFB_ALERT="@@TAMPER_TTFB_ALERT_S@@"
CERT_MIN_DAYS="@@TAMPER_CERT_MIN_DAYS@@"
DISK_MAX="@@TAMPER_DISK_MAX_PCT@@"
LOG=/var/log/systema-tamper-watch.log
NAME=tamper_watch

SELFTEST=0
if [ "${1:-}" = "--test-url" ] && [ -n "${2:-}" ]; then
  HOME_URL="$2"; SELFTEST=1
fi

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

FAIL=0
# http_probe <url> <max-time> → на stdout «code ttfb», при таймауте «000 x».
http_probe() {
  local out
  out=$(curl -s -o /dev/null -w '%{http_code} %{time_starttransfer}' --max-time "$2" "$1" 2>/dev/null) || true
  printf '%s\n' "${out:-000 0}"
}

GREENS=(); FAILS=()
green() { GREENS+=("GREEN — $1"); }
fail()  { FAILS+=("FAIL — $1"); FAIL=1; }

# ── 1. Главная: 200 + маркер заголовка + TTFB ────────────────────────────────
BODY=$(curl -s --max-time 30 "$HOME_URL" 2>/dev/null) || true
read -r code ttfb <<< "$(http_probe "$HOME_URL" 30)"
title_ok=0
# here-string, НЕ `printf … | grep -q`: при `set -o pipefail` ранний выход grep
# по первому совпадению шлёт printf SIGPIPE (141) — пайплайн «не совпал», хотя
# маркер найден (поймано живым прогоном 11-09-2026).
grep -qF "$TITLE" <<< "$BODY" && title_ok=1
awk "BEGIN{exit !($ttfb >= $TTFB_ALERT)}" && slow=alert || slow=""
if [ "$code" = "200" ] && [ "$title_ok" = 1 ] && [ -z "$slow" ]; then
  green "GET / $code, маркер «$TITLE» есть, TTFB ${ttfb}s"
else
  detail="GET / code=$code ttfb=${ttfb}s маркер=$([ "$title_ok" = 1 ] && echo есть || echo НЕТ)"
  if awk "BEGIN{exit !($ttfb >= $TTFB_ALERT)}"; then
    fail "$detail (TTFB ≥ ${TTFB_ALERT}s — волна FPM или подмена; урок samskrtam: 25s жили неделями)"
  else
    fail "$detail"
  fi
fi
# мягкая граница — только заметка в бриф, не FAIL
awk "BEGIN{exit !($ttfb >= $TTFB_WARN && $ttfb < $TTFB_ALERT)}" \
  && green "  ℹ TTFB ${ttfb}s ≥ warn-порога ${TTFB_WARN}s — следить"

# ── 2. Глубокий маршрут: 200 + TTFB ─────────────────────────────────────────
read -r code ttfb <<< "$(http_probe "$DEEP_URL" 30)"
if [ "$code" = "200" ] && ! awk "BEGIN{exit !($ttfb >= $TTFB_ALERT)}"; then
  green "GET $DEEP_URL $code, TTFB ${ttfb}s"
else
  fail "GET $DEEP_URL code=$code ttfb=${ttfb}s"
fi

# ── 3. TLS-сертификат: дней до конца ────────────────────────────────────────
END=$(echo | openssl s_client -servername "$CERT_HOST" -connect "$CERT_HOST:$CERT_PORT" 2>/dev/null \
      | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
END_EPOCH=$(date -d "$END" +%s 2>/dev/null || echo 0)
DAYS=$(( (END_EPOCH - $(date -u +%s)) / 86400 ))
if [ "$DAYS" -ge "$CERT_MIN_DAYS" ]; then
  green "сертификат $CERT_HOST: $DAYS дн до конца (мин $CERT_MIN_DAYS)"
else
  fail "сертификат $CERT_HOST: $DAYS дн до конца (мин $CERT_MIN_DAYS) enddate=${END:-не-читается}"
fi

# ── 4. Диск: процент занятости корня ────────────────────────────────────────
PCT=$(df --output=pcent / 2>/dev/null | tail -1 | tr -dc '0-9')
if [ -n "$PCT" ] && [ "$PCT" -le "$DISK_MAX" ]; then
  green "диск /: ${PCT}% (макс $DISK_MAX%)"
else
  fail "диск /: ${PCT:-?}% (макс $DISK_MAX%)"
fi

# ── Бриф ─────────────────────────────────────────────────────────────────────
{
  echo "Tamper-watch samskrte.ru $(TS)Z$([ "$SELFTEST" = 1 ] && echo ' [SELFTEST]')"
  for g in "${GREENS[@]}"; do echo "$g"; done
  for f in "${FAILS[@]}"; do echo "$f"; done
} > "$BRIEF_DIR/${NAME}_latest.md"

if [ "$FAIL" = 1 ]; then
  log "FAIL ${#FAILS[@]} проб(ы)"
  [ "$SELFTEST" = 1 ] || page_or_queue "P1" "tamper-watch .92: ${#FAILS[@]} FAIL по samskrte.ru (см. brief/tamper_watch_latest.md)"
  hb_mark "$NAME" 2
  exit 2
fi
log "OK все пробы зелёные"
hb_mark "$NAME" 0
exit 0
