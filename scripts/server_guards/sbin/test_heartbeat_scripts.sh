#!/bin/bash
# Fixtures for cologne-cdsl-heartbeat.sh + samskrte-internals-heartbeat.sh (H7556).
# Hermetic: fake curl/runuser/supervisorctl/df on PATH, sed-substituted paths —
# no network, no /etc/default, no prod files are touched, on any host.
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/bin" "$TMP/app"
: > "$TMP/app/schedule.log"
LOG="$TMP/curl.log"
: > "$LOG"

# fake curl — logs argv, behaviour per FAKE_* env:
#   GET check            -> prints $FAKE_CHECK_BODY, exit $FAKE_CHECK_EXIT
#   site -w http_code    -> prints $FAKE_SITE_CODE
#   POST ping direct     -> exit $FAKE_PING_EXIT
#   POST ping with -x    -> exit $FAKE_PROXY_PING_EXIT
cat > "$TMP/bin/curl" <<'EOF'
#!/bin/bash
printf '%s\n' "$*" >> "${FAKE_CURL_LOG:?}"
proxy=0
for a in "$@"; do [ "$a" = "-x" ] && proxy=1; done
case " $* " in
  *" -X POST "*)
    if [ "$proxy" = 1 ]; then
      # FAKE_PROXY_RETRY_OK: first proxy POST of the run fails (transient DNS
      # churn shape), later ones succeed — exercises the hb_post retry.
      if [ -n "${FAKE_PROXY_RETRY_OK:-}" ]; then
        if [ -f "${FAKE_PROXY_STATE:?}" ]; then exit 0; fi
        : > "${FAKE_PROXY_STATE:?}"
        exit "${FAKE_PROXY_PING_EXIT:-7}"
      fi
      exit "${FAKE_PROXY_PING_EXIT:-7}"
    fi
    exit "${FAKE_PING_EXIT:-0}"
    ;;
  *"%{http_code}"*) echo "${FAKE_SITE_CODE:-200}"; exit 0 ;;
  *) printf '%s' "${FAKE_CHECK_BODY:-Cologne Digital Sanskrit Dictionaries}"; exit "${FAKE_CHECK_EXIT:-0}" ;;
esac
EOF

# fake runuser — stands in for `runuser -u www-data -- php artisan db:monitor`
cat > "$TMP/bin/runuser" <<'EOF'
#!/bin/bash
exit "${FAKE_DB_EXIT:-0}"
EOF

# fake supervisorctl — `status <svc>` -> RUNNING or $FAKE_SUPERVISOR
cat > "$TMP/bin/supervisorctl" <<'EOF'
#!/bin/bash
[ "$1" = "status" ] && { echo "${2:-svc} ${FAKE_SUPERVISOR:-RUNNING}"; exit 0; }
exit 3
EOF

# fake df — stable "45%" for the disk guard
cat > "$TMP/bin/df" <<'EOF'
#!/bin/bash
echo " 45%"
EOF

# fake stat — `-c %Y <file>` always fresh (FAKE_SCHEDULE_AGE sec ago or now)
cat > "$TMP/bin/stat" <<'EOF'
#!/bin/bash
[ "$1" = "-c" ] && { echo $(( $(date +%s) - ${FAKE_SCHEDULE_AGE:-0} )); exit 0; }
exit 1
EOF
chmod +x "$TMP/bin/curl" "$TMP/bin/runuser" "$TMP/bin/supervisorctl" "$TMP/bin/df" "$TMP/bin/stat"

# Hermetic copies: /etc/default sources redirected to a nonexistent path,
# /var/www/html -> $TMP/app (internals script only).
sed -e "s|/etc/default/cologne-cdsl-heartbeat|$TMP/none-cologne|" \
  "$HERE/cologne-cdsl-heartbeat.sh" > "$TMP/cologne.sh"
sed -e "s|/etc/default/samskrte-internals-heartbeat|$TMP/none-internals|g" \
  -e "s|/var/www/html|$TMP/app|g" \
  "$HERE/samskrte-internals-heartbeat.sh" > "$TMP/internals.sh"
chmod +x "$TMP/cologne.sh" "$TMP/internals.sh"

FAIL=0
ok_posts()      { grep -c -- "-X POST .*hb.invalid.* --data-binary ok" "$LOG" 2>/dev/null || true; }
fail_posts()    { grep -c -- "-X POST .*/fail" "$LOG" 2>/dev/null || true; }
proxy_posts()   { grep -c -- " -x " "$LOG" 2>/dev/null || true; }

run_cologne() {
  : > "$LOG"
  rm -f "$TMP/proxy-state"
  env PATH="$TMP/bin:$PATH" FAKE_CURL_LOG="$LOG" FAKE_PROXY_STATE="$TMP/proxy-state" \
    COLOGNE_HEARTBEAT_URL="http://hb.invalid/api/v1/heartbeat/T" \
    COLOGNE_CHECK_URL="http://check.invalid/" HEARTBEAT_PROXY="${HEARTBEAT_PROXY:-}" \
    bash "$TMP/cologne.sh" 2>&1
}

run_internals() {
  : > "$LOG"
  rm -f "$TMP/proxy-state"
  env PATH="$TMP/bin:$PATH" FAKE_CURL_LOG="$LOG" FAKE_PROXY_STATE="$TMP/proxy-state" \
    INTERNALS_HEARTBEAT_URL="http://hb.invalid/api/v1/heartbeat/T" \
    HEARTBEAT_PROXY="${HEARTBEAT_PROXY:-}" \
    bash "$TMP/internals.sh" 2>&1
}

check() { # name expected_exit expected_grep [ok_posts] [fail_posts] [proxy_posts]
  name="$1"; want_exit="$2"; want_grep="$3"
  want_ok="${4:-}"; want_fail="${5:-}"; want_proxy="${6:-}"
  if [ "$got_exit" != "$want_exit" ]; then
    echo "FAIL $name: exit $got_exit != $want_exit"; echo "$got_out"; FAIL=1; return
  fi
  if ! printf '%s' "$got_out" | grep -qF "$want_grep"; then
    echo "FAIL $name: output lacks '$want_grep'"; echo "$got_out"; FAIL=1; return
  fi
  for pair in "ok_posts:$want_ok" "fail_posts:$want_fail" "proxy_posts:$want_proxy"; do
    fn="${pair%%:*}"; want="${pair#*:}"
    [ -z "$want" ] && continue
    got=$(eval "$fn")
    if [ "$got" != "$want" ]; then
      echo "FAIL $name: $fn=$got want $want"; echo "--- curl.log:"; cat "$LOG"; FAIL=1; return
    fi
  done
  echo "ok   $name"
}

# --- cologne -----------------------------------------------------------------
got_out=$(run_cologne); got_exit=$?
check "cologne ok direct"               0 "OK:"                            1 0 0

FAKE_CHECK_BODY="Error establishing a database connection"; export FAKE_CHECK_BODY
got_out=$(run_cologne); got_exit=$?
check "cologne keyword missing"         1 "FAIL: keyword missing"          0 1 0
unset FAKE_CHECK_BODY

FAKE_CHECK_EXIT=7; export FAKE_CHECK_EXIT
got_out=$(run_cologne); got_exit=$?
check "cologne check unreachable"       1 "FAIL: curl failed or timeout"   0 1 0
unset FAKE_CHECK_EXIT

FAKE_PING_EXIT=7; export FAKE_PING_EXIT
got_out=$(run_cologne); got_exit=$?
check "cologne ping dead, no proxy"     1 "FAIL: heartbeat ping failed"    1 1 0

HEARTBEAT_PROXY="socks5h://127.0.0.1:1080"; FAKE_PROXY_PING_EXIT=0
export HEARTBEAT_PROXY FAKE_PROXY_PING_EXIT
got_out=$(run_cologne); got_exit=$?
check "cologne proxy fallback ok"       0 "OK:"                            2 0 1

FAKE_PROXY_PING_EXIT=7; export FAKE_PROXY_PING_EXIT
got_out=$(run_cologne); got_exit=$?
check "cologne proxy dead too"          1 "FAIL: heartbeat ping failed"    3 3 4
unset FAKE_PING_EXIT FAKE_PROXY_PING_EXIT

FAKE_PING_EXIT=7; FAKE_PROXY_PING_EXIT=7; FAKE_PROXY_RETRY_OK=1
export FAKE_PING_EXIT FAKE_PROXY_PING_EXIT FAKE_PROXY_RETRY_OK
got_out=$(run_cologne); got_exit=$?
check "cologne proxy transient then ok" 0 "OK:"                            3 0 2
unset FAKE_PING_EXIT FAKE_PROXY_PING_EXIT FAKE_PROXY_RETRY_OK HEARTBEAT_PROXY

# --- internals ---------------------------------------------------------------
got_out=$(run_internals); got_exit=$?
check "internals ok direct"             0 "OK:"                            1 0 0

FAKE_SUPERVISOR=STOPPED; export FAKE_SUPERVISOR
got_out=$(run_internals); got_exit=$?
check "internals supervisor down"       1 "FAIL: supervisor horizon not RUNNING" 0 1 0
unset FAKE_SUPERVISOR

FAKE_SITE_CODE=502; export FAKE_SITE_CODE
got_out=$(run_internals); got_exit=$?
check "internals site not 200"          1 "HTTP 502"                       0 1 0
unset FAKE_SITE_CODE

FAKE_PING_EXIT=7; export FAKE_PING_EXIT
got_out=$(run_internals); got_exit=$?
check "internals ping dead, no proxy"   1 "FAIL: heartbeat ping failed"    1 1 0

HEARTBEAT_PROXY="socks5h://127.0.0.1:1080"; FAKE_PROXY_PING_EXIT=0
export HEARTBEAT_PROXY FAKE_PROXY_PING_EXIT
got_out=$(run_internals); got_exit=$?
check "internals proxy fallback ok"     0 "OK:"                            2 0 1
unset FAKE_PING_EXIT FAKE_PROXY_PING_EXIT HEARTBEAT_PROXY

FAKE_PING_EXIT=7; FAKE_PROXY_PING_EXIT=7; FAKE_PROXY_RETRY_OK=1
export FAKE_PING_EXIT FAKE_PROXY_PING_EXIT FAKE_PROXY_RETRY_OK
HEARTBEAT_PROXY="socks5h://127.0.0.1:1080"; export HEARTBEAT_PROXY
got_out=$(run_internals); got_exit=$?
check "internals proxy transient then ok" 0 "OK:"                           3 0 2
unset FAKE_PING_EXIT FAKE_PROXY_PING_EXIT FAKE_PROXY_RETRY_OK HEARTBEAT_PROXY

if [ "$FAIL" = 0 ]; then echo "ALL PASS"; else echo "FIXTURES FAILED"; fi
exit "$FAIL"
