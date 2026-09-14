#!/bin/bash
# test_systema_ib_balance_watch.sh — reproduction test for systema-ib-balance-watch.sh
# (H-ib-balance, MG 14-09-2026). Runs entirely in a temp dir with a stubbed
# lane_lib: no prod access, no root, no secrets.
#
# Cases:
#   1. fresh marker                → exit 0, GREEN brief, no page
#   2. stale marker                → exit 2, one page, FAIL brief
#   3. second stale run in the hour → exit 2, still ONE page (dedup)
#   4. recovery (fresh again)      → exit 0, dedup state reset
#   5. marker missing              → exit 2 with "отсутствует" reason + page
#   6. unreadable marker           → exit 1, operational WARN, no page
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
GUARD="$HERE/systema-ib-balance-watch.sh"
FAILED=0

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# ── stubbed lane_lib ─────────────────────────────────────────────────────────
cat > "$TMP/lane_lib.sh" <<'LIB'
BRIEF_DIR="${BRIEF_DIR:-$TMP/brief}"
mkdir -p "$BRIEF_DIR"
tg_send() { echo "TG: $1" >> "$BRIEF_DIR/tg.log"; return 0; }
page_or_queue() {
  if [ "$1" = "P1" ]; then echo "P1: $2" >> "$BRIEF_DIR/pages.log"; else echo "$1: $2" >> "$BRIEF_DIR/pages.log"; fi
}
hb_mark() { date -u +%s > "$HB_DIR/${1}.stamp"; }
LIB

export BRIEF_DIR="$TMP/brief"
export IB_STATE_DIR="$TMP/state"
export LANE_LIB="$TMP/lane_lib.sh"
export HB_DIR="$TMP/hb"
# conf-driven numbers, rendered here the way server_guards_apply.sh would
export IB_STALE_MIN=10
export IB_PAGE_EVERY=3600
mkdir -p "$BRIEF_DIR" "$IB_STATE_DIR" "$HB_DIR"

run() { # run <marker-epoch|MISSING|GARBAGE> <now> → sets RC
  case "$1" in
    MISSING) rm -f "$TMP/marker" ;;
    GARBAGE) printf 'not-a-number' > "$TMP/marker" ;;
    *) printf '%s' "$1" > "$TMP/marker" ;;
  esac
  export IB_MARKER="$TMP/marker" IB_NOW="$2"
  bash "$GUARD" >/dev/null 2>&1
  RC=$?
}

check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then echo "ok   — $1"; else echo "FAIL — $1: expected [$2] got [$3]"; FAILED=1; fi
}

NOW=1800000000

# 1. fresh marker (30 s old)
run $((NOW - 30)) "$NOW"
check "fresh marker → exit 0" 0 "$RC"
grep -q "GREEN" "$BRIEF_DIR/ib_balance_watch_latest.md" && echo "ok   — GREEN brief written" || { echo "FAIL — no GREEN brief"; FAILED=1; }
[ ! -s "$BRIEF_DIR/pages.log" ] && echo "ok   — no page on fresh" || { echo "FAIL — paged on fresh"; FAILED=1; }

# 2. stale marker (11 min old > 10 min threshold)
run $((NOW - 11 * 60)) "$NOW"
check "stale marker → exit 2" 2 "$RC"
PAGES=$(grep -c '^P1:' "$BRIEF_DIR/pages.log" 2>/dev/null || echo 0)
check "stale marker → exactly one page" 1 "$PAGES"
grep -q "FAIL" "$BRIEF_DIR/ib_balance_watch_latest.md" && echo "ok   — FAIL brief written" || { echo "FAIL — no FAIL brief"; FAILED=1; }

# 3. second stale run, 5 min later — dedup (≤1 page/hour)
run $((NOW - 11 * 60)) $((NOW + 300))
check "second stale run → exit 2" 2 "$RC"
PAGES=$(grep -c '^P1:' "$BRIEF_DIR/pages.log" 2>/dev/null || echo 0)
check "second stale run → still one page (dedup)" 1 "$PAGES"

# 4. recovery: fresh run clears dedup state
run $((NOW + 360)) $((NOW + 400))
check "recovery → exit 0" 0 "$RC"
[ ! -f "$IB_STATE_DIR/ib_balance_watch.last_page" ] && echo "ok   — dedup state reset on recovery" || { echo "FAIL — dedup state not reset"; FAILED=1; }

# 5. marker missing → stale with the "отсутствует" reason, pages again
run MISSING $((NOW + 500))
check "missing marker → exit 2" 2 "$RC"
grep -q "отсутствует" "$BRIEF_DIR/ib_balance_watch_latest.md" && echo "ok   — missing-marker reason in brief" || { echo "FAIL — wrong reason for missing marker"; FAILED=1; }

# 6. unreadable marker → operational exit 1, no new page
BEFORE=$(grep -c '^P1:' "$BRIEF_DIR/pages.log" 2>/dev/null || echo 0)
run GARBAGE $((NOW + 600))
check "unreadable marker → exit 1" 1 "$RC"
AFTER=$(grep -c '^P1:' "$BRIEF_DIR/pages.log" 2>/dev/null || echo 0)
check "unreadable marker → no page" "$BEFORE" "$AFTER"

if [ "$FAILED" = 0 ]; then echo "ALL OK"; else echo "TESTS FAILED"; exit 1; fi
