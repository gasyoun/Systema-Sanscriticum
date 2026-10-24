#!/usr/bin/env bash
# test_lesson_packs_sync.sh — H7069: selftest для lesson-packs-sync.sh.
#
# Проверяем не «скрипт запускается», а контракт: пин отвергается, когда он
# не тот; второй прогон — no-op без записи; dry-run не пишет; расхождение
# объявляется диффом до записи; пустой архив не превращается в «пусто и ладно».
# Каждый отказ проверяется на синтетическом нарушении — зелёный на пустом
# входе не доказательство (docs/PLAYBOOK_EVIDENCE_OF_DONE_2026.md).
#
#   bash scripts/server_guards/sbin/test_lesson_packs_sync.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HELPER="$SCRIPT_DIR/lesson-packs-sync.sh"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
DEPLOY_SH="$REPO_ROOT/deploy.sh"

PASS=0
FAIL=0
pass() { PASS=$((PASS + 1)); printf 'PASS: %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf 'FAIL: %s\n' "$1"; }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/lyw-sync-test.XXXXXX")"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

# ── Синтетический источник: тег с ровно девятью паками ──────────────────────
SRC="$WORK/src"
mkdir -p "$SRC"
git -C "$SRC" init -q
git -C "$SRC" config user.email test@example.invalid
git -C "$SRC" config user.name "H7069 test"

mk_pack() {
  local dir="$SRC/KocherginaUchebnik_1998/LessonPacks/zan1/$1"
  mkdir -p "$dir/views"
  printf '{"schema":"lyw-pack-v1","zan":1,"level":"%s"}\n' "$1" > "$dir/manifest.json"
  printf '{"schema":"lyw-quiz-v1"}\n' > "$dir/quizzes.json"
  printf 'текст пака %s\n' "$1" > "$dir/personalized_text.md"
  printf 'mindmap %s\n' "$1" > "$dir/views/mindmap.mmd"
}
mk_pack base
for i in yoga ayurveda kino palomnichestvo; do
  mk_pack "nol/$i"
  mk_pack "prodolzhayushchiy/$i"
done

git -C "$SRC" add -A
git -C "$SRC" commit -qm "packs"
git -C "$SRC" tag -a kochergina-uchebnik-v0.19.0 -m "pin"
PIN_COMMIT="$(git -C "$SRC" rev-parse 'kochergina-uchebnik-v0.19.0^{commit}')"

DEST="$WORK/live/zan1"
STAMP="$WORK/live/.lyw-packs-stamp"
export LYW_PACKS_MIRROR="$SRC"
export LYW_PACKS_TAG="kochergina-uchebnik-v0.19.0"
export LYW_PACKS_TAG_COMMIT="$PIN_COMMIT"
export LYW_PACKS_DEST="$DEST"
export LYW_PACKS_STAMP="$STAMP"
export LYW_APP_USER="$(id -un)"

run() { bash "$HELPER" "$@" 2>&1; }

echo "== 1. первый прогон: паки приезжают =="
out="$(run)"; rc=$?
check "1a exit 0" "[ $rc -eq 0 ]"
check "1b девять манифестов на месте" "[ \"\$(find '$DEST' -name manifest.json | wc -l | tr -d ' ')\" = 9 ]"
check "1c stamp записан с тегом" "grep -qx 'tag=kochergina-uchebnik-v0.19.0' '$STAMP'"
check "1d stamp несёт запиненный коммит" "grep -qx 'commit=$PIN_COMMIT' '$STAMP'"
check "1e структура уровня сохранена" "[ -f '$DEST/nol/yoga/manifest.json' ] && [ -f '$DEST/base/manifest.json' ]"
check "1f сказал, что выложил" "printf '%s' \"\$out\" | grep -q 'выложены'"

NOTICE="$WORK/live/LYW_PACKS_NOTICE.md"
check "1g лицензионная записка записана рядом с паками" "[ -f '$NOTICE' ] && grep -q 'kochergina-uchebnik-v0.19.0' '$NOTICE' && grep -q 'MIT' '$NOTICE'"
check "1h записка вне zan1 (--delete её не съест)" "[ -f '$WORK/live/LYW_PACKS_NOTICE.md' ] && [ ! -e '$DEST/LYW_PACKS_NOTICE.md' ]"

echo "== 2. второй прогон подряд: no-op без записи =="
before="$(stat -f '%m' "$STAMP" 2>/dev/null || stat -c '%Y' "$STAMP")"
sleep 1
out="$(run)"
rc=$?
after="$(stat -f '%m' "$STAMP" 2>/dev/null || stat -c '%Y' "$STAMP")"
check "2a exit 0" "[ $rc -eq 0 ]"
check "2b сказал no-op" "printf '%s' \"\$out\" | grep -q 'no-op'"
check "2c stamp не переписан" "[ '$before' = '$after' ]"

# no-op прогон обязан восстанавливать записку: иначе первый деплой после её
# появления (паки уже совпадают) оставил бы паки без записки до следующего
# изменения содержимого.
rm -f "$WORK/live/LYW_PACKS_NOTICE.md"
out="$(run)"
check "2d no-op восстановил лицензионную записку" "[ -f '$WORK/live/LYW_PACKS_NOTICE.md' ]"
check "2e и всё ещё говорит no-op" "printf '%s' \"\$out\" | grep -q 'no-op'"

echo "== 3. dry-run на пустой цели ничего не пишет =="
rm -rf "$WORK/live"
out="$(run --dry-run)"
rc=$?
check "3a exit 0" "[ $rc -eq 0 ]"
check "3b цели не создал" "[ ! -e '$DEST' ]"
check "3c объявил создание" "printf '%s' \"\$out\" | grep -q 'будет создан'"
check "3d сказал dry-run" "printf '%s' \"\$out\" | grep -q 'запись не выполнялась'"

echo "== 4. расхождение объявляется диффом и восстанавливается =="
run >/dev/null
printf 'ручная правка\n' >> "$DEST/base/personalized_text.md"
out="$(run)"
rc=$?
check "4a exit 0" "[ $rc -eq 0 ]"
check "4b дифф напечатан до записи" "printf '%s' \"\$out\" | grep -q '^[+-]ручная правка'"
check "4c ручная правка убрана" "! grep -q 'ручная правка' '$DEST/base/personalized_text.md'"

echo "== 5. чужой/пропавший пин отвергается =="
out="$(LYW_PACKS_TAG='no-such-tag' run)"
rc=$?
check "5a exit 2 на пропавшем теге" "[ $rc -eq 2 ]"
check "5b назвал причину" "printf '%s' \"\$out\" | grep -q 'нет тега'"

out="$(LYW_PACKS_TAG_COMMIT='0000000000000000000000000000000000000000' run)"
rc=$?
check "5c exit 2 на чужом коммите" "[ $rc -eq 2 ]"
check "5d назвал оба коммита" "printf '%s' \"\$out\" | grep -q 'а запинен'"

out="$(LYW_PACKS_MIRROR='$WORK/no-mirror' run)"
rc=$?
check "5e exit 2 без зеркала" "[ $rc -eq 2 ]"

echo "== 6. неполный архив не считается нормой =="
rm -rf "$SRC/KocherginaUchebnik_1998/LessonPacks/zan1/nol/kino"
git -C "$SRC" add -A
git -C "$SRC" commit -qm "drop one pack"
git -C "$SRC" tag -a broken-pin -m "broken"
BROKEN_COMMIT="$(git -C "$SRC" rev-parse 'broken-pin^{commit}')"
out="$(LYW_PACKS_TAG=broken-pin LYW_PACKS_TAG_COMMIT="$BROKEN_COMMIT" run)"
rc=$?
check "6a exit 3 на 8 манифестах" "[ $rc -eq 3 ]"
check "6b назвал число" "printf '%s' \"\$out\" | grep -q '8 манифест'"
check "6c живые паки не тронуты" "[ -f '$DEST/nol/kino/manifest.json' ]"

echo "== 7. проводка в deploy.sh =="
check "7a deploy.sh зовёт lesson-packs-sync.sh" "grep -q 'lesson-packs-sync.sh' '$DEPLOY_SH'"
sync_line="$(grep -n 'lesson-packs-sync.sh' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
verify_line="$(grep -n 'php artisan guards:verify' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
check "7b синхронизация стоит ДО guards:verify" "[ -n '$sync_line' ] && [ -n '$verify_line' ] && [ '$sync_line' -lt '$verify_line' ]"
check "7c сбой шага не роняет деплой в rollback (#1143)" "! grep -q 'exit 1.*lesson-packs-sync' '$DEPLOY_SH'"

echo "== 8. решение не зависит от владельца цели =="
# Живой прогон 19-10-2026: root-овый rsync -a видел «владелец не тот» у каждого
# файла (цель — www-data) и переписывал все 36 файлов на каждом деплое, то есть
# no-op не наступал НИКОГДА. Владельца решает финальный chown, а не rsync.
check "8a у rsync есть --no-owner --no-group" "grep -q -- '--no-owner --no-group' '$HELPER'"
check "8b опции общие для решения и записи" "[ \"\$(grep -c 'RSYNC_OPTS\[@\]' '$HELPER')\" -ge 2 ]"
check "8c голого rsync -a --delete не осталось" "! grep -q 'rsync -a --delete' '$HELPER'"

echo
if [ "$FAIL" -eq 0 ]; then
  printf '✔ test_lesson_packs_sync: %d/%d PASSED — пак из пина, повторный прогон не пишет, пустой архив отвергается\n' "$PASS" "$((PASS + FAIL))"
  exit 0
fi
printf '✖ test_lesson_packs_sync: %d упало, %d прошло\n' "$FAIL" "$PASS"
exit 1
