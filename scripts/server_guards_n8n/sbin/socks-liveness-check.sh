#!/bin/bash
# socks-liveness-check.sh — SOCKS CHANNEL liveness for socks-nl (Systema #4429).
#
# MANAGED FILE с H8564 (23-11-2026) — ставится scripts/server_guards_apply.sh
# --profile n8n из scripts/server_guards_n8n/sbin/socks-liveness-check.sh.
# Править копию в репозитории, не на сервере: расхождение видит
# server_guards_n8n_verify.sh. До H8564 файл жил только на машине (создан
# сессией #4429 23-11-2026) — пересборка LXC стёрла бы его молча.
#
# 22-11-2026: systemd said active for 8h39, TCP 1080 was open, SSH keepalives
# were answered — and forwarding was wedged, killing all privoxy-routed hosts.
# Therefore: actually complete a SOCKS CONNECT to two independent targets.
# One bounded self-heal, then alarm through .92 (independent Telegram egress).
#
# H8564 добавляет ГЛАВНОЕ, чего этот скрипт не мог сказать о себе: на каждом
# УСПЕШНОМ прогоне он пушит метку ОФФ-БОКС на .92 через forced-command ключ
# (юзер socks91-witness, authorized_keys разрешает ровно `touch` одной метки):
#   /var/lib/systema-guards/socks91_hub.last
# Читатель метки — systema-socks91-watch.sh на .92 (свой таймер, порог 15 мин).
# Тишина метки = либо туннель мёртв (тогда скрипт молчит штатно), либо встал
# ЭТОТ таймер, либо .91 мертва. Ни то, ни другое сам скрипт доказать не может.
#
# Крик (.91 → .92) больше НЕ несёт секретов: по ssh уходит только ТЕКСТ, а
# доставляет его .92 каноническим «Вестником» (uprava_alert → @samskrte_bot →
# «Отдел заботы», рулинг MG 23-11-2026). Legacy-бот testpodpiska12_bot из этой
# цепочки убран.
#
# Тестовые швы: SOCKS_LIVENESS_PORT, SOCKS_LIVENESS_TARGETS, SOCKS_LIVENESS_NO_HEAL,
# SOCKS_LIVENESS_LABEL, SOCKS_LIVENESS_NO_PUSH, SOCKS91_WITNESS_KEY/_USER/_HOST.
set -uo pipefail
PORT="${SOCKS_LIVENESS_PORT:-1080}"
TARGETS="${SOCKS_LIVENESS_TARGETS:-https://www.google.com https://api.zoom.us}"
TIMEOUT=8
NO_HEAL="${SOCKS_LIVENESS_NO_HEAL:-0}"
NO_PUSH="${SOCKS_LIVENESS_NO_PUSH:-0}"
LABEL="${SOCKS_LIVENESS_LABEL:-}"
LOG=/var/log/socks-liveness.log
STATE=/run/socks-liveness.alarmed
SUPPRESS=1800
RELAY_HOST=192.168.200.92
RELAY_SCRIPT=/usr/local/sbin/socks-alarm-relay.sh
WITNESS_USER="${SOCKS91_WITNESS_USER:-socks91-witness}"
WITNESS_HOST="${SOCKS91_WITNESS_HOST:-192.168.200.92}"
WITNESS_KEY="${SOCKS91_WITNESS_KEY:-/root/.ssh/id_socks91_witness}"
TS() { date -u "+%Y-%m-%dT%H:%M:%SZ"; }
log() { printf "%s %s\n" "$(TS)" "$*" >> "$LOG"; }

probe_round() {
  local u c
  for u in $TARGETS; do
    c=$(curl -sS --socks5-hostname "127.0.0.1:$PORT" -o /dev/null -w "%{http_code}" --max-time "$TIMEOUT" "$u" 2>/dev/null) || c=000
    case ",$c," in ,000,|,) ;; *) echo "$c"; return 0 ;; esac
  done
  echo 000
}

# push_heartbeat — метка для внешнего свидетеля .92. Best-effort: провал пуша
# НЕ меняет код возврата пробы (он сам станет тишиной и закричит оттуда).
push_heartbeat() {
  [ "$NO_PUSH" = 1 ] && { log "WITNESS push skipped (SOCKS_LIVENESS_NO_PUSH=1)"; return 0; }
  if [ ! -r "$WITNESS_KEY" ]; then
    log "WITNESS push SKIP: нет ключа $WITNESS_KEY"
    return 0
  fi
  local err
  err=$(ssh -i "$WITNESS_KEY" -o BatchMode=yes -o ConnectTimeout=10 \
        -o StrictHostKeyChecking=accept-new "$WITNESS_USER@$WITNESS_HOST" /bin/true 2>&1)
  local rc=$?
  if [ "$rc" -eq 0 ]; then
    log "WITNESS push ok"
  else
    log "WITNESS push FAILED rc=$rc: $(printf '%s' "$err" | tail -n 1 | cut -c1-160)"
  fi
  return 0
}

alarm() {
  local msg="$LABEL $1" rc
  # На .92 уходит ТОЛЬКО текст: доставку делает канонический «Вестник»
  # (uprava_alert). Секретов на .91 нет вовсе.
  printf "%s\n" "$msg" |
    ssh -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "root@$RELAY_HOST" "$RELAY_SCRIPT"
  rc=$?
  log "ALARM relay_rc=$rc"
  return "$rc"
}

code=$(probe_round)
[ "$code" = 000 ] && { sleep 3; code=$(probe_round); }
if [ "$code" != 000 ]; then
  rm -f "$STATE"
  log "OK socks=127.0.0.1:$PORT code=$code"
  push_heartbeat
  exit 0
fi

log "FAIL socks=127.0.0.1:$PORT (both targets 000 after 2 rounds)"
if [ "$NO_HEAL" != 1 ]; then
  systemctl restart socks-nl.service >/dev/null 2>&1
  sleep 5
  code=$(probe_round)
  if [ "$code" != 000 ]; then
    rm -f "$STATE"
    log "HEALED by one restart code=$code"
    push_heartbeat
    alarm "🛠 vps91 socks-nl forwarding wedged — auto-restarted, probe $code OK"
    exit 0
  fi
else
  log "HEAL SKIPPED (negative control)"
fi

now=$(date +%s)
last=$(stat -c %Y "$STATE" 2>/dev/null || echo 0)
if [ -f "$STATE" ] && [ $((now - last)) -lt "$SUPPRESS" ]; then
  log "ALARM suppressed (last within ${SUPPRESS}s)"
  exit 1
fi
touch "$STATE"
alarm "🚨 vps91 socks-nl CONNECT CHANNEL DEAD: privoxy-routed hosts (google/telegram/openrouter) failing. Tunnel restart did NOT help. Check exit node 103.112.71.201 / systemd socks-nl.service. Incident: Systema #4429"
exit 1
