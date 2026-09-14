#!/usr/bin/env bash
# test_systema_remediation_ladder.sh — проверка лестницы восстановления (W3c).
#
# Целиком во временном каталоге: ни прода, ни root, ни сети. systemctl, docker,
# curl и приёмник тревог подменяются заглушками через PATH, поэтому проверяется
# НАСТОЯЩИЙ scripts/server_guards/sbin/systema-remediation-ladder.sh, а не
# пересказ его логики рядом. Пересказ расходится с оригиналом на второй правке.
#
# Что доказывается (ровно приёмка волны 3,
# docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md §1 Wave 3):
#   1. здоровая машина: лестница не трогает НИЧЕГО и держит счётчик на нуле;
#   2. R2: лежащий юнит перезапускается и это записано;
#   3. R2 не дёргает живые цели, когда болит не то, что он умеет чинить;
#   4. R4 БЕЗ ТОКЕНА: в журнале «would restart», эскалация человеку, и НИ ОДНОГО
#      обращения к Proxmox API — «пишет и не действует» проверяется по отсутствию
#      следа, а не по доверию к тексту скрипта;
#   5. исчерпанный счётчик: громкое «сдаюсь» и НИ ОДНОГО действия;
#   6. счётчик переживает чистку /tmp (он не в /tmp — иначе R3 стирал бы память
#      о самом себе ровно тогда, когда она нужна).
#
# Usage: bash scripts/server_guards/sbin/test_systema_remediation_ladder.sh
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$SCRIPT_DIR/systema-remediation-ladder.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0; FAIL=0
ok()   { printf '  ok    %s\n' "$*"; PASS=$((PASS+1)); }
bad()  { printf '  FAIL  %s\n' "$*"; FAIL=$((FAIL+1)); }
check(){ if [ "$1" = 0 ]; then ok "$2"; else bad "$2"; fi; }

# ── Отрендерить шаблон так же, как это делает server_guards_apply.sh ─────────
LADDER="$TMP/ladder.sh"
sed -e 's#@@LADDER_TARGETS@@#unit:fake-a,unit:fake-b#' \
    -e 's#@@LADDER_SMOKE_URL@@#http://smoke.invalid/#' \
    -e 's#@@LADDER_SMOKE_EXPECT@@#200#' \
    -e 's#@@LADDER_SMOKE_WAIT_SECONDS@@#10#' \
    -e 's#@@LADDER_MAX_ATTEMPTS@@#3#' \
    -e 's#@@LADDER_TMP_AGE_DAYS@@#10#' \
    -e 's#@@LADDER_CT_ID@@#150#' \
    -e 's#@@LADDER_BOX_NAME@@#testbox#' \
    "$SRC" > "$LADDER"
chmod +x "$LADDER"
if grep -q '@@' "$LADDER"; then
  bad "в шаблоне осталась неподставленная @@…@@ — тест не может быть честным"
  grep -n '@@' "$LADDER" | head; exit 1
fi

# ── Заглушки ────────────────────────────────────────────────────────────────
BIN="$TMP/bin"; mkdir -p "$BIN"
STATE="$TMP/state"; LOGF="$TMP/ladder.log"; LEDG="$TMP/ledger.ndjson"
TMPROOT="$TMP/faketmp"; mkdir -p "$TMPROOT"
CURL_TRACE="$TMP/curl-calls.txt"
ALERT_TRACE="$TMP/alerts.txt"
RESTART_TRACE="$TMP/restarts.txt"

cat > "$BIN/systemctl" <<'EOF'
#!/usr/bin/env bash
# is-active --quiet <unit>   -> код из $TMPD/unit-<name>.state (active|dead)
# restart <unit>             -> записать след и пометить unit активным, если разрешено
TMPD="${LADDER_TEST_TMP:?}"
case "$1" in
  is-active) u="$3"; [ "$(cat "$TMPD/unit-$u.state" 2>/dev/null)" = active ] ;;
  restart)   u="$2"; echo "restart $u" >> "$TMPD/restarts.txt"
             if [ "$(cat "$TMPD/restart-heals" 2>/dev/null)" = 1 ]; then echo active > "$TMPD/unit-$u.state"; fi ;;
  *) exit 0 ;;
esac
EOF

cat > "$BIN/curl" <<'EOF'
#!/usr/bin/env bash
TMPD="${LADDER_TEST_TMP:?}"
printf '%s\n' "$*" >> "$TMPD/curl-calls.txt"
# Заглушка ПОВТОРЯЕТ поведение настоящего curl, а не удобное: при недоступном
# хосте он печатает 000 через -w И возвращает ненулевой код. Ранняя версия этой
# заглушки всегда выходила с нулём, и из-за этого мимо теста проехал живой
# дефект — «code=000000» в журнале (поймано на .92 26-08-2026). Заглушка,
# которая добрее оригинала, проверяет не то, что поедет на прод.
code=000
for a in "$@"; do case "$a" in http://smoke.invalid/) code=$(cat "$TMPD/smoke-code" 2>/dev/null || printf '000') ;; esac; done
printf '%s' "$code"
[ "$code" = 000 ] && exit 7
exit 0
EOF

cat > "$BIN/alertsink" <<'EOF'
#!/usr/bin/env bash
TMPD="${LADDER_TEST_TMP:?}"
printf '%s | %s | %s\n' "$1" "$3" "$4" >> "$TMPD/alerts.txt"
EOF

chmod +x "$BIN/systemctl" "$BIN/curl" "$BIN/alertsink"
export LADDER_TEST_TMP="$TMP"
export PATH="$BIN:$PATH"

run_ladder() { # run_ladder [args...] -> печатает код возврата
  env SYSTEMA_LADDER_SELFTEST=1 \
      SYSTEMA_LADDER_STATE_DIR="$STATE" \
      SYSTEMA_LADDER_LOG="$LOGF" \
      SYSTEMA_LADDER_LEDGER="$LEDG" \
      SYSTEMA_LADDER_ALERT_BIN="$BIN/alertsink" \
      SYSTEMA_LADDER_TMP_ROOT="$TMPROOT" \
      PROXMOX_API_TOKEN="${PROXMOX_API_TOKEN:-}" \
      bash "$LADDER" "$@" >/dev/null 2>&1
  printf '%s' "$?"
}

reset_world() {
  rm -rf "$STATE" "$LOGF" "$LEDG" "$CURL_TRACE" "$ALERT_TRACE" "$RESTART_TRACE"
  echo active > "$TMP/unit-fake-a.state"
  echo active > "$TMP/unit-fake-b.state"
  echo 200    > "$TMP/smoke-code"
  echo 0      > "$TMP/restart-heals"
  unset PROXMOX_API_TOKEN
}

echo "== 1. здоровая машина: ничего не делаем, счётчик 0 =="
reset_world
rc=$(run_ladder)
[ "$rc" = 0 ] && ok "код возврата 0" || bad "ожидали 0, получили $rc"
[ ! -s "$RESTART_TRACE" ] && ok "ни одного перезапуска" || bad "лестница перезапускала здоровое: $(cat "$RESTART_TRACE")"
[ "$(cat "$STATE/remediation-attempts" 2>/dev/null)" = 0 ] && ok "счётчик обнулён чистым успехом" || bad "счётчик не 0"

echo "== 2. R2: лежащий юнит перезапущен и вылечен =="
reset_world
echo dead > "$TMP/unit-fake-a.state"; echo 1 > "$TMP/restart-heals"
rc=$(run_ladder)
[ "$rc" = 0 ] && ok "R2 довёл до успеха (код 0)" || bad "ожидали 0, получили $rc"
grep -q 'restart fake-a' "$RESTART_TRACE" 2>/dev/null && ok "fake-a перезапущен" || bad "fake-a не перезапускался"
grep -q 'restart fake-b' "$RESTART_TRACE" 2>/dev/null && bad "здоровый fake-b тоже дёрнули" || ok "здоровый fake-b не тронут"
grep -q '"rung":"R2","verdict":"success"' "$LEDG" 2>/dev/null && ok "R2 success в машинном журнале" || bad "нет записи R2 success"
[ "$(cat "$STATE/remediation-attempts" 2>/dev/null)" = 0 ] && ok "счётчик сброшен после успеха" || bad "счётчик не сброшен"

echo "== 3. смок красный, но все цели живы: R2 не дёргает здоровое =="
reset_world
echo 503 > "$TMP/smoke-code"
rc=$(run_ladder)
[ ! -s "$RESTART_TRACE" ] && ok "ни одного перезапуска здоровых целей" || bad "дёрнули здоровые: $(cat "$RESTART_TRACE")"
grep -q '"rung":"R2","verdict":"skipped"' "$LEDG" 2>/dev/null && ok "R2 честно записан как skipped" || bad "нет записи R2 skipped"

echo "== 4. R4 БЕЗ ТОКЕНА: пишет «would restart», эскалирует, НЕ действует =="
reset_world
echo 503 > "$TMP/smoke-code"; echo dead > "$TMP/unit-fake-a.state"; echo 0 > "$TMP/restart-heals"
rc=$(run_ladder)
[ "$rc" = 4 ] && ok "код возврата 4 (инертная R4)" || bad "ожидали 4, получили $rc"
grep -q 'R4 INERT: would restart ct 150' "$LOGF" 2>/dev/null && ok "в журнале «would restart ct 150»" || bad "нет строки would restart"
grep -q '"rung":"R4","verdict":"inert"' "$LEDG" 2>/dev/null && ok "R4 inert в машинном журнале" || bad "нет записи R4 inert"
grep -q 'ERROR' "$ALERT_TRACE" 2>/dev/null && ok "эскалация человеку ушла" || bad "эскалации не было"
# Главная проверка ступени: НИ ОДНОГО обращения к Proxmox API.
grep -qiE 'PVEAPIToken|api2/json' "$CURL_TRACE" 2>/dev/null && bad "лестница ходила в Proxmox API без токена!" || ok "к Proxmox API не обращались ни разу"

echo "== 5. счётчик исчерпан: громкое «сдаюсь», НИ ОДНОГО действия =="
reset_world
echo 503 > "$TMP/smoke-code"; echo dead > "$TMP/unit-fake-a.state"
mkdir -p "$STATE"; printf '3' > "$STATE/remediation-attempts"
rc=$(run_ladder)
[ "$rc" = 3 ] && ok "код возврата 3 (сдались)" || bad "ожидали 3, получили $rc"
grep -q 'GAVE UP' "$LOGF" 2>/dev/null && ok "в журнале «GAVE UP»" || bad "сдались молча — это дефект"
[ ! -s "$RESTART_TRACE" ] && ok "после потолка ни одного перезапуска" || bad "действовала после потолка: $(cat "$RESTART_TRACE")"
grep -q 'ERROR' "$ALERT_TRACE" 2>/dev/null && ok "человеку сказали" || bad "человеку не сказали"

echo "== 5b. недоступный смок пишет в журнал 000, а не 000000 =="
# Регрессия на живой дефект 26-08-2026: `curl … || printf '000'` складывал
# вывод -w и запасное значение. Вердикт был верный, врала строка журнала — а
# разбирают аварию по ней.
reset_world
echo 000 > "$TMP/smoke-code"
rc=$(run_ladder)
grep -q 'smoke red: .* -> 000$' "$LOGF" 2>/dev/null && ok "код в журнале ровно 000" || bad "в журнале не 000: $(grep -o 'smoke red:.*' "$LOGF" | head -1)"

echo "== 5c. --dry-run НЕ будит людей и НИЧЕГО не делает =="
# Репетиция, поднимающая настоящую тревогу, приучает не верить тревогам.
reset_world
echo 503 > "$TMP/smoke-code"; echo dead > "$TMP/unit-fake-a.state"
rc=$(run_ladder --dry-run --stub-unhealthy)
[ ! -s "$ALERT_TRACE" ] && ok "ни одного сообщения человеку из репетиции" || bad "репетиция послала тревогу: $(cat "$ALERT_TRACE")"
[ ! -s "$RESTART_TRACE" ] && ok "ни одного перезапуска из репетиции" || bad "репетиция перезапускала: $(cat "$RESTART_TRACE")"
grep -q 'DRY-RUN would: R2 restart unit fake-a' "$LOGF" 2>/dev/null && ok "репетиция сказала, что СДЕЛАЛА БЫ" || bad "репетиция промолчала о намерении"
[ "$(cat "$STATE/remediation-attempts" 2>/dev/null || echo 0)" = 0 ] && ok "счётчик репетицией не тронут" || bad "репетиция сожгла попытку"

echo "== 6. R3 чистит /tmp, но НЕ стирает счётчик =="
reset_world
echo 503 > "$TMP/smoke-code"
old="$TMPROOT/aged-scratch"; : > "$old"; touch -d '30 days ago' "$old"
mkdir -p "$STATE"; printf '1' > "$STATE/remediation-attempts"
rc=$(run_ladder)
[ ! -e "$old" ] && ok "состаренный объект в /tmp удалён" || bad "R3 не удалил состаренное"
[ -f "$STATE/remediation-attempts" ] && ok "счётчик пережил чистку (лежит вне /tmp)" || bad "R3 стёр собственный счётчик"
[ "$(cat "$STATE/remediation-attempts")" -ge 2 ] && ok "счётчик вырос перед действием" || bad "счётчик не рос: $(cat "$STATE/remediation-attempts")"

printf '\n%s\n' "-----------------------------------------"
printf 'PASS %s   FAIL %s\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ] || exit 1
