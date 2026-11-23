#!/bin/bash
# socks-alarm-relay.sh — независимый путь крика для SOCKS-пробы .91 (H8564, #4429).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/socks-alarm-relay.sh. Править копию в репозитории.
#
# ЗАЧЕМ. Telegram-выход .91 идёт через socks-nl (privoxy → 127.0.0.1:1080),
# поэтому крик, отправленный С .91, умирает вместе с той самой поломкой, о
# которой он кричит. У .92 выход в Telegram прямой (замер 23-11-2026: 302 за
# 0.26 с). Поэтому .91 присылает СЮДА по приватному LAN-SSH только ТЕКСТ.
#
# ЧТО ИЗМЕНИЛОСЬ (H8564, 23-11-2026). До этого здесь был свой curl к legacy-боту
# `testpodpiska12_bot`, а токен с чатом .91 гнала по stdin — секрет ходил по
# сети, а приёмником был не «Отдел заботы» (рулинг MG 23-11-2026: уведомления
# Ивану — ТОЛЬКО в «Отдел заботы», бот @samskrte_bot). Теперь доставка —
# канонический `uprava_alert` (H4686/C8): тот же бот, тот же чат, тот же
# receipt-JSONL. Секреты не покидают .92: ключи бота живут в
# /root/.config/uprava/alert.env (chmod 600, вне git).
#
# ПРОТОКОЛ ВХОДА. STDIN = ровно один ТЕКСТ сообщения (может быть многострочным).
# Legacy-формат (три строки: токен, чат, текст) ОТВЕРГАЕТСЯ с exit 78 и в лог,
# без эха: иначе в переходный момент токен уехал бы в чат как текст крика.
#
# Коды: 0 доставлено · 1 отказ доставки (сеть/Telegram) · 78 пустой или
# неприемлемый вход (в том числе legacy-формат и отсутствие uprava_alert).
set -uo pipefail

ALERT_BIN="${SOCKS_ALARM_ALERT_BIN:-/usr/local/bin/uprava_alert}"
LOG=/var/log/socks-alarm-relay.log

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >> "$LOG" 2>/dev/null || true; }

PAYLOAD=$(cat)
[ -n "$PAYLOAD" ] || { log "relay REFUSED: пустой stdin"; exit 78; }

# Legacy-переход (H8564): старый .91 слал токен, чат и текст тремя строками.
LINES=$(printf '%s\n' "$PAYLOAD" | wc -l | tr -d '[:space:]')
if [ "${LINES:-0}" -ge 3 ]; then
  log "relay REFUSED: legacy 3-строчный формат (секрет не пересылается, текст не отправлен)"
  exit 78
fi

[ -x "$ALERT_BIN" ] || { log "relay REFUSED: нет $ALERT_BIN — канал «Вестник» не развёрнут"; exit 78; }

OUT=$("$ALERT_BIN" "$PAYLOAD" 2>&1); RC=$?
if [ "$RC" -ne 0 ]; then
  log "relay FAILED rc=$RC: $(printf '%s' "$OUT" | tail -n 2 | tr '\n' ' ' | cut -c1-200)"
  exit 1
fi
log "relay OK $(printf '%s' "$OUT" | tail -n 1 | cut -c1-160) text_len=${#PAYLOAD}"
exit 0
