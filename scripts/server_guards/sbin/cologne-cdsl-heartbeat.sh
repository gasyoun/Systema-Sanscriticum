#!/bin/bash
# cologne-cdsl-heartbeat.sh — check Cologne CDSL from this VPS, report to Better Stack.
# Not on uni-koeln; probes https://sanskrit-lexicon.uni-koeln.de/ (ops 30-07-2026).
# 01-10-2026: keyword test without grep -q — under `set -o pipefail` grep -q exits
# at the first match, echo gets SIGPIPE (141), the pipeline counts as failed and the
# unit fails EVEN THOUGH the keyword matched. Intermittent (race on pipe buffer):
# false "FAIL: keyword missing" alerts on 22/26/28/29/30-09-2026.
set -uo pipefail

CHECK_URL="${COLOGNE_CHECK_URL:-https://sanskrit-lexicon.uni-koeln.de/}"
KEYWORD="${COLOGNE_KEYWORD:-Cologne Digital Sanskrit Dictionaries}"
HB="${COLOGNE_HEARTBEAT_URL:-}"
TIMEOUT="${COLOGNE_CURL_TIMEOUT:-30}"

if [ -z "$HB" ] && [ -f /etc/default/cologne-cdsl-heartbeat ]; then
  # shellcheck disable=SC1091
  . /etc/default/cologne-cdsl-heartbeat
  HB="${COLOGNE_HEARTBEAT_URL:-}"
fi

# H7556 31-10-2026: optional proxy fallback for the PING leg only. RU-upstream
# filtering has been dropping TLS flows to Better Stack's Cloudflare IPs
# (172.66.0.0/13) from .92/.91 since 29-10 22:29Z while every check stays green
# — see docs/UPTIME_BETTERSTACK_MONITORING.md §2.6. HEARTBEAT_PROXY empty =
# previous behaviour (direct only). Semantics are unchanged fail-closed: a failed
# check never pings ok, and a ping that cannot be delivered still FAILs the unit.
HEARTBEAT_PROXY="${HEARTBEAT_PROXY:-}"

hb_post() {
  # $1 = full ping URL, $2 = body. Direct first; proxy fallback only if set.
  # Proxy leg retries once: the userspace-tailscaled socks DNS lookup has rare
  # sub-5s timeouts during tailnet path churn (measured 31-10-2026 08:44:19Z,
  # journalctl -u tailscaled «lookup uptime.betterstack.com: i/o timeout»).
  curl -fsS -m 15 -X POST "$1" --data-binary "$2" >/dev/null 2>&1 && return 0
  [ -n "$HEARTBEAT_PROXY" ] || return 1
  curl -fsS -m 20 -x "$HEARTBEAT_PROXY" -X POST "$1" --data-binary "$2" >/dev/null 2>&1 && return 0
  curl -fsS -m 20 -x "$HEARTBEAT_PROXY" -X POST "$1" --data-binary "$2" >/dev/null 2>&1
}

stamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }

fail() {
  msg="$1"
  echo "$(stamp) FAIL: $msg"
  if [ -n "$HB" ]; then
    hb_post "${HB}/fail" "$msg" || true
  fi
  exit 1
}

[ -n "$HB" ] || fail "COLOGNE_HEARTBEAT_URL empty"

body=$(curl -fsS -L -m "$TIMEOUT" "$CHECK_URL" 2>/dev/null) \
  || fail "curl failed or timeout after ${TIMEOUT}s: $CHECK_URL"

# no -q: consume the whole body so the writer never sees EPIPE (see header note)
echo "$body" | grep -F "$KEYWORD" >/dev/null \
  || fail "keyword missing: $KEYWORD"

hb_post "$HB" "ok" || fail "heartbeat ping failed"

echo "$(stamp) OK: $CHECK_URL"
