#!/bin/bash
# samskrte-internals-heartbeat.sh — SELF-check of .92 internals an external HTTP monitor cannot see:
# local site route, Laravel DB, horizon+reverb (supervisor), schedule.log freshness, disk.
# Pattern: cologne-cdsl-heartbeat.sh. Agent 18-09-2026 (repurposed heartbeat 477251, MG ask).
set -uo pipefail
HB="${INTERNALS_HEARTBEAT_URL:-}"
[ -f /etc/default/samskrte-internals-heartbeat ] && . /etc/default/samskrte-internals-heartbeat
HB="${INTERNALS_HEARTBEAT_URL:-}"
TIMEOUT="${INTERNAL_CURL_TIMEOUT:-25}"

# H7556 31-10-2026: optional proxy fallback for the PING leg only — RU-upstream
# filtering drops TLS flows to Better Stack's Cloudflare IPs from .92 since
# 29-10 22:29Z while every internal check stays green; see
# docs/UPTIME_BETTERSTACK_MONITORING.md §2.6. Empty = previous behaviour.
# Fail-closed semantics unchanged: checks gate the ping, delivery failure FAILs.
HEARTBEAT_PROXY="${HEARTBEAT_PROXY:-}"

hb_post() {
  # $1 = full ping URL, $2 = body. Direct first; proxy fallback only if set.
  curl -fsS -m 15 -X POST "$1" --data-binary "$2" >/dev/null 2>&1 && return 0
  [ -n "$HEARTBEAT_PROXY" ] || return 1
  curl -fsS -m 20 -x "$HEARTBEAT_PROXY" -X POST "$1" --data-binary "$2" >/dev/null 2>&1
}

fail() {
  msg="$1"
  echo "$(date -u '+%FT%TZ') FAIL: $msg"
  if [ -n "$HB" ]; then hb_post "$HB/fail" "$msg" || true; fi
  exit 1
}
[ -n "$HB" ] || fail "INTERNALS_HEARTBEAT_URL empty"

code=$(curl -sS -o /dev/null -w '%{http_code}' -m "$TIMEOUT" https://samskrte.ru/ 2>/dev/null) || code=000
[ "$code" = "200" ] || fail "local site route samskrte.ru -> HTTP $code"

cd /var/www/html || fail "app dir missing"
runuser -u www-data -- php artisan db:monitor >/dev/null 2>&1 || fail "Laravel DB unreachable (db:monitor)"

for svc in horizon reverb; do
  supervisorctl status "$svc" 2>/dev/null | grep -q RUNNING || fail "supervisor $svc not RUNNING"
done

[ $(( $(date +%s) - $(stat -c %Y /var/www/html/storage/logs/schedule.log 2>/dev/null || echo 0) )) -lt 600 ] || fail "schedule.log stale (>10 min) — Laravel scheduler stalled"

used=$(df --output=pcent / | tail -1 | tr -dc '0-9')
[ "$used" -lt 90 ] || fail "disk / at ${used}%"

hb_post "$HB" "ok" || fail "heartbeat ping failed"
echo "$(date -u '+%FT%TZ') OK: site 200, db ok, horizon+reverb running, schedule fresh, disk ${used}%"
