#!/bin/bash
# test_systema_socks91_watch.sh — герметичный selftest свидетеля SOCKS-метки .91
# (H8564, Systema #4429 п.2).
#
# Запуск: bash scripts/server_guards/sbin/test_systema_socks91_watch.sh
# Где угодно, где есть GNU `stat -c` (Linux). Сеть, /var/lib/systema-guards и
# настоящий канал «Вестник» НЕ трогаются: все пути — во временном каталоге, а
# uprava_alert подменён фейком, который только пишет argv в свой лог.
#
# Что доказывается:
#   1. свежая метка            → exit 0, GREEN в бриф, канал НЕ зван;
#   2. метка старше порога     → exit 2, FAIL в бриф, страница отправлена;
#   3. второй прогон в окне    → exit 2, дедуп: второго вызова канала нет;
#   4. GREEN после дедупа      → окно сброшено, следующий разрыв снова пейджит;
#   5. метки нет               → exit 2, страница (молчание = «ни разу не пушил»);
#   6. канал отказал           → exit 1, строка в pending_pages.txt (fail-LOUD).
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
WATCH="$HERE/systema-socks91-watch.sh"
[ -x "$WATCH" ] || WATCH="bash $WATCH"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
MARK="$TMP/socks91_hub.last"
STATE="$TMP/state"
BRIEF="$TMP/brief"
ALERT_LOG="$TMP/alert.log"
mkdir -p "$STATE" "$BRIEF"
: > "$ALERT_LOG"

# Фейковый канал: пишет одну строку на вызов, код возврата — из FAKE_ALERT_RC.
cat > "$TMP/fake_alert" <<'EOF'
#!/bin/bash
printf '%s\n' "$*" >> "${FAKE_ALERT_LOG:?}"
exit "${FAKE_ALERT_RC:-0}"
EOF
chmod +x "$TMP/fake_alert"

FAILED=0
pass() { printf '  ok       %s\n' "$*"; }
fail() { FAILED=$((FAILED+1)); printf '  FAIL     %s\n' "$*"; }

run_watch() { # <now_epoch>
  SOCKS91_MARKER="$MARK" SOCKS91_STATE_DIR="$STATE" BRIEF_DIR="$BRIEF" \
  SOCKS91_ALERT_BIN="$TMP/fake_alert" SOCKS91_NOW="$1" \
  LANE_LIB="$TMP/нет-такого-lane-lib.sh" FAKE_ALERT_LOG="$ALERT_LOG" \
  FAKE_ALERT_RC="${FAKE_ALERT_RC:-0}" \
  bash "$HERE/systema-socks91-watch.sh" >/dev/null 2>&1
  echo $?
}

alerts() { [ -f "$ALERT_LOG" ] && wc -l < "$ALERT_LOG" | tr -d ' ' || echo 0; }
NOW=$(date -u +%s)
mark_age() { touch -d "@$1" "$MARK"; }

# 1. Свежая метка — GREEN, канал молчит.
mark_age "$NOW"
rc=$(run_watch "$NOW")
[ "$rc" = 0 ] && pass "свежая метка → exit 0" || fail "свежая метка: ожидался exit 0, получен $rc"
grep -q GREEN "$BRIEF/socks91_watch_latest.md" && pass "GREEN записан в бриф" || fail "GREEN не попал в бриф"
[ "$(alerts)" = 0 ] && pass "канал не зван на GREEN" || fail "канал зван на GREEN ($(alerts) вызовов)"

# 2. Метка старше порога (порог 1 мин) — FAIL + страница.
mark_age $((NOW - 3600))
rc=$(SOCKS91_STALE_MIN=1 run_watch "$NOW")
[ "$rc" = 2 ] && pass "тишина → exit 2" || fail "тишина: ожидался exit 2, получен $rc"
grep -q "СТРАНИЦА ОТПРАВЛЕНА" "$BRIEF/socks91_watch_latest.md" && pass "FAIL + страница в бриф" || fail "страница не отражена в брифе"
[ "$(alerts)" = 1 ] && pass "канал зван ровно один раз" || fail "ожидался 1 вызов канала, получено $(alerts)"

# 3. Второй прогон в окне дедупа — страницы нет.
rc=$(SOCKS91_STALE_MIN=1 run_watch $((NOW + 60)))
[ "$rc" = 2 ] && pass "дедуп: exit 2" || fail "дедуп: ожидался exit 2, получен $rc"
grep -q "задедуплена" "$BRIEF/socks91_watch_latest.md" && pass "дедуп отражён в брифе" || fail "дедуп не отражён в брифе"
[ "$(alerts)" = 1 ] && pass "дедуп: второго вызова канала нет" || fail "дедуп протёк: $(alerts) вызовов"

# 4. GREEN сбрасывает окно — следующий разрыв снова пейджит.
mark_age $((NOW + 120))
rc=$(SOCKS91_STALE_MIN=1 run_watch $((NOW + 120)))
[ "$rc" = 0 ] && pass "сброс окна: exit 0" || fail "сброс окна: ожидался exit 0, получен $rc"
mark_age $((NOW + 120 - 3600))
rc=$(SOCKS91_STALE_MIN=1 run_watch $((NOW + 120)))
[ "$rc" = 2 ] && pass "новый разрыв: exit 2" || fail "новый разрыв: ожидался exit 2, получен $rc"
[ "$(alerts)" = 2 ] && pass "новый разрыв пейджит снова" || fail "новый разрыв не пейджил ($(alerts) вызовов)"

# 5. Метки нет вовсе.
rm -f "$MARK"
rm -f "$STATE/socks91_watch.last_page" "$BRIEF/socks91_watch_latest.md"
rc=$(SOCKS91_STALE_MIN=1 run_watch $((NOW + 7200)))
[ "$rc" = 2 ] && pass "метки нет → exit 2" || fail "метки нет: ожидался exit 2, получен $rc"
grep -q "метки свидетеля нет" "$BRIEF/socks91_watch_latest.md" && pass "отсутствие метки названо" || fail "отсутствие метки не названо"

# 6. Канал отказал — громко.
rm -f "$STATE/socks91_watch.last_page"
rc=$(SOCKS91_STALE_MIN=1 FAKE_ALERT_RC=1 run_watch $((NOW + 10800)))
[ "$rc" = 1 ] && pass "отказ канала → exit 1" || fail "отказ канала: ожидался exit 1, получен $rc"
grep -q "ДОСТАВКА НЕ УДАЛАСЬ" "$BRIEF/socks91_watch_latest.md" && pass "отказ канала отражён в брифе" || fail "отказ канала не отражён в брифе"

printf '\n'
if [ "$FAILED" = 0 ]; then echo "SELFTEST PASS"; exit 0; fi
echo "SELFTEST FAIL ($FAILED)"; exit 1
