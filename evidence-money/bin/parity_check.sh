#!/bin/sh
# H6326 — canon parity check: page figure vs FINDINGS §1580 SQL for a named
# month. PASS iff |page - canon| <= 1 RUB. Run on vps92:
#   bin/parity_check.sh 2026-09
# The page side greps the BUILT static output (Evidence embeds query results
# in the build chunks, thousands-separator form tolerated); the canon side
# runs the exact §1580 SQL via the read-only build credentials.
set -eu
MONTH="${1:-2026-09}"
APP_DIR=/opt/evidence-money
Y="${MONTH%-*}"; M="${MONTH#*-}"
FROM="$Y-$M-01"
TO="$(date -d "$FROM +1 month" '+%Y-%m-%d')"
PW=$(grep -oP '(?<=password: ).*' "$APP_DIR/sources/systema/connection.yaml" | tr -d '"'"'")

CANON=$(mysql --default-character-set=utf8mb4 -h 127.0.0.1 -u evidence_ro -p"$PW" \
  laravel -N -e "SELECT ROUND(SUM(amount)) FROM payments WHERE status='paid' AND first_paid_at >= '$FROM' AND first_paid_at < '$TO' AND tariff NOT IN ('Расход','salary_payout')")

# Search the built output for the canon number; Evidence renders with
# thousands separators in comma OR dot form — accept plain/comma/dot.
SEP_C="${CANON%???},${CANON#???}"
SEP_D="${CANON%???}.${CANON#???}"
PAGE=$(grep -rhoE "${CANON}|${SEP_C}|${SEP_D}" "$APP_DIR/build" 2>/dev/null | head -1 | tr -d ',.')

[ -n "$PAGE" ] || { echo "PARITY FAIL: month figure $CANON/$SEP for $MONTH not found in build output"; exit 1; }
DIFF=$(( CANON - PAGE )); AD=${DIFF#-}
echo "month=$MONTH canon_rub=$CANON page_rub=$PAGE diff_rub=$AD"
if [ "$AD" -le 1 ]; then echo "PARITY PASS (<= 1 RUB)"; else echo "PARITY FAIL"; exit 1; fi
