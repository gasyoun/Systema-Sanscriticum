#!/usr/bin/env bash
# lesson-packs-sync.sh — H7069: паки уроков LYW приезжают деплоем из пина,
# а не ручной копией.
#
# Зачем. 18-10-2026 девять паков заливки zan1 скопировали на .92 руками
# (`rsync` из клона SanskritGrammar), потому что `storage/` не в git. Это
# работало ровно до первого восстановления storage, пересборки сервера или
# второго бокса: конфиг при этом продолжает говорить `LYW_ENABLED=true`, и
# фича молча отдаёт 404 вместо паков. Здесь та же копия стала шагом деплоя.
#
# Контракт (MG 18-10-2026, путь «а»):
#   • ПИН. Содержимое берётся из тега `kochergina-uchebnik-v0.19.0`, а не из
#     ветки: тег неизменяем, поэтому «что было выложено» воспроизводится
#     побайтово. Коммит тега тоже запинен — зеркало, уехавшее на другой
#     коммит под тем же именем тега, отвергается (код 2).
#   • ОФЛАЙН. Источник — локальное bare-зеркало SanskritGrammar на боксе
#     (`git archive` по тегу). Сеть на проде в пути деплоя не нужна: падение
#     GitHub не превращается в «паки пропали». Зеркало ставится один раз
#     (docs/server-resource-guards.md §17), деплой его только читает.
#   • ИДЕМПОТЕНТНО. Решение «менять/не менять» принимает `rsync --dry-run
#     --itemize-changes`: второй прогон подряд — no-op без записи, без
#     повторного chown и без нового stamp.
#   • ОБЪЯВЛЯЕТ. Перед записью печатается unified diff расходящихся файлов
#     (обрезается, чтобы не залить deploys.log): правило «деплой не меняет
#     системный конфиг молча» распространяется и на контент.
#   • НЕ ТРОГАЕТ. Только `storage/app/lesson-packs/zan1`. Код, конфиг, `.env`,
#     данные студентов и соседние каталоги storage не затрагиваются —
#     `--delete` ограничен целью синхронизации.
#
# Коды возврата (деплой их не превращает в откат, см. issue #1143):
#   0 — синхронизировано или уже совпадает (no-op)
#   2 — нет зеркала / нет тега / тег указывает не на запиненный коммит
#   3 — в архиве тега не те 9 манифестов (пин сломан, а не «пусто»)
#   4 — rsync упал
#   5 — цель недоступна для записи
#
# Использование:
#   bash scripts/server_guards/sbin/lesson-packs-sync.sh            # объявить+применить
#   bash scripts/server_guards/sbin/lesson-packs-sync.sh --dry-run  # объявить, не писать
#   bash scripts/server_guards/sbin/lesson-packs-sync.sh --force    # перезаписать даже при совпадении
#
# Переопределения (тесты, второй бокс):
#   LYW_APP_DIR, LYW_PACKS_MIRROR, LYW_PACKS_TAG, LYW_PACKS_TAG_COMMIT,
#   LYW_PACKS_SUBTREE, LYW_PACKS_DEST, LYW_PACKS_STAMP, LYW_APP_USER,
#   LYW_PACKS_DIFF_LINES.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${LYW_APP_DIR:-$(cd "$SCRIPT_DIR/../../.." && pwd)}"

MIRROR="${LYW_PACKS_MIRROR:-/var/lib/systema/lyw-packs/SanskritGrammar.git}"
TAG="${LYW_PACKS_TAG:-kochergina-uchebnik-v0.19.0}"
TAG_COMMIT="${LYW_PACKS_TAG_COMMIT:-d270693e84f138fe4c5d008f4357445cdd87002a}"
SUBTREE="${LYW_PACKS_SUBTREE:-KocherginaUchebnik_1998/LessonPacks/zan1}"
DEST="${LYW_PACKS_DEST:-$APP_DIR/storage/app/lesson-packs/zan1}"
STAMP="${LYW_PACKS_STAMP:-$APP_DIR/storage/app/lesson-packs/.lyw-packs-stamp}"
APP_USER="${LYW_APP_USER:-www-data}"
DIFF_LINES="${LYW_PACKS_DIFF_LINES:-120}"
EXPECTED_MANIFESTS=9

DRY_RUN=0
FORCE=0
QUIET=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --force) FORCE=1 ;;
    --quiet) QUIET=1 ;;
    -h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "lesson-packs-sync: неизвестный аргумент: $arg" >&2; exit 1 ;;
  esac
done

say() { [ "$QUIET" = 1 ] || printf '%s\n' "$*"; }
err() { printf 'lesson-packs-sync: %s\n' "$*" >&2; }

for tool in git rsync tar diff; do
  command -v "$tool" >/dev/null 2>&1 || { err "нет $tool — синхронизировать нечем"; exit 2; }
done

# ── Источник: пин в локальном зеркале ────────────────────────────────────────
if [ ! -d "$MIRROR" ]; then
  err "нет зеркала $MIRROR — паки брать неоткуда (поставить: docs/server-resource-guards.md §17)"
  exit 2
fi

if ! commit="$(git -C "$MIRROR" rev-parse --verify --quiet "${TAG}^{commit}")" || [ -z "$commit" ]; then
  err "в зеркале $MIRROR нет тега $TAG — пин сломан"
  exit 2
fi

if [ "$commit" != "$TAG_COMMIT" ]; then
  err "тег $TAG в зеркале указывает на $commit, а запинен $TAG_COMMIT — зеркало чужое, не синхронизирую"
  exit 2
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/lyw-packs-stage.XXXXXX")"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

# --strip-components=3 срезает KocherginaUchebnik_1998/LessonPacks/zan1/ —
# в staging остаётся ровно то дерево, которое ждёт LessonPackRepository:
# base/, nol/<interest>/, prodolzhayushchiy/<interest>/.
if ! git -C "$MIRROR" archive --format=tar "$TAG" -- "$SUBTREE" | tar -x -C "$STAGE" --strip-components=3; then
  err "git archive $TAG:$SUBTREE не распаковался"
  exit 2
fi

manifests="$(find "$STAGE" -name manifest.json -type f | wc -l | tr -d ' ')"
files="$(find "$STAGE" -type f | wc -l | tr -d ' ')"
if [ "$manifests" != "$EXPECTED_MANIFESTS" ]; then
  err "в архиве $TAG:$SUBTREE $manifests манифест(ов) вместо $EXPECTED_MANIFESTS — пин сломан, паки не трогаю"
  exit 3
fi

# ── Цель ────────────────────────────────────────────────────────────────────
if [ -e "$DEST" ] && [ ! -d "$DEST" ]; then
  err "$DEST существует и это не каталог — синхронизировать некуда"
  exit 5
fi
dest_parent="$(dirname "$DEST")"
if [ ! -d "$dest_parent" ]; then
  if [ "$DRY_RUN" = 1 ]; then
    say "dry-run: каталог $dest_parent будет создан"
  else
    mkdir -p "$dest_parent" || { err "не создаётся $dest_parent"; exit 5; }
  fi
fi
if [ -d "$DEST" ] && [ ! -w "$DEST" ]; then
  err "$DEST не доступен для записи ($(id -un))"
  exit 5
fi

# ── Что изменится ───────────────────────────────────────────────────────────
# Решение принимает тот же движок, который будет применять: itemize-changes в
# сухом прогоне. Пустой список = содержимое уже то самое, второй прогон — no-op.
# --no-owner --no-group: владельца цели решает только финальный chown. Иначе
# прогон из-под root видел «владелец не тот» у каждого файла и переписывал всё
# заново даже при побайтово том же содержимом — no-op не наступал никогда
# (поймано живым прогоном на .92, 19-10-2026).
RSYNC_OPTS=(-a --no-owner --no-group)
changes="$(rsync "${RSYNC_OPTS[@]}" -n --itemize-changes --delete "$STAGE"/ "$DEST"/ 2>/dev/null | grep -v '^\.d' || true)"
change_count="$(printf '%s\n' "$changes" | grep -c '[^[:space:]]' || true)"

if [ "$change_count" = 0 ] && [ "$FORCE" = 0 ] && [ -f "$STAMP" ] && grep -qx "tag=$TAG" "$STAMP" 2>/dev/null; then
  say "паки LYW уже совпадают с $TAG ($manifests манифестов, $files файлов) — no-op"
  exit 0
fi

if [ "$change_count" != 0 ]; then
  say "паки LYW расходятся с $TAG: $change_count строка(и) изменений"
  if [ -d "$DEST" ]; then
    diff_out="$(diff -ruN --label "live/$SUBTREE" --label "pin/$TAG" "$DEST" "$STAGE" 2>/dev/null || true)"
    if [ -n "$diff_out" ]; then
      printf '%s\n' "$diff_out" | head -n "$DIFF_LINES"
      total="$(printf '%s\n' "$diff_out" | wc -l | tr -d ' ')"
      if [ "$total" -gt "$DIFF_LINES" ]; then
        say "… diff обрезан: $DIFF_LINES из $total строк"
      fi
    fi
  else
    say "каталога $DEST ещё нет — будет создан целиком"
  fi
fi

if [ "$DRY_RUN" = 1 ]; then
  say "dry-run: запись не выполнялась"
  exit 0
fi

# ── Применить ───────────────────────────────────────────────────────────────
if ! rsync "${RSYNC_OPTS[@]}" --delete "$STAGE"/ "$DEST"/; then
  err "rsync в $DEST упал"
  exit 4
fi

if [ "$(id -u)" = 0 ] || [ "$(id -un 2>/dev/null || true)" = "$APP_USER" ]; then
  chown -R "$APP_USER:$APP_USER" "$DEST" 2>/dev/null || true
fi

stamp_tmp="$STAMP.tmp.$$"
{
  printf 'tag=%s\n' "$TAG"
  printf 'commit=%s\n' "$TAG_COMMIT"
  printf 'subtree=%s\n' "$SUBTREE"
  printf 'manifests=%s\n' "$manifests"
  printf 'files=%s\n' "$files"
  printf 'synced=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$stamp_tmp" && mv -f "$stamp_tmp" "$STAMP"
chmod 644 "$STAMP" 2>/dev/null || true

# ── Licence note рядом с паками (вне zan1: --delete его не съест) ────────────
NOTICE="$(dirname "$DEST")/LYW_PACKS_NOTICE.md"
NOTICE_BODY="# Паки уроков LYW (zan1)

Источник: SanskritGrammar, тег \`$TAG\` (коммит \`$TAG_COMMIT\`), путь
\`$SUBTREE\`. Содержимое генерируется \`scripts/build_lessonpack.py\` из
материалов курса; лицензия репозитория-источника — MIT
(https://github.com/gasyoun/SanskritGrammar/blob/main/LICENSE).

Выложено шагом деплоя \`scripts/server_guards/sbin/lesson-packs-sync.sh\`
(H7069). Руками здесь не править: следующий деплой перезапишет дерево zan1.
"
if [ ! -f "$NOTICE" ] || ! printf '%s' "$NOTICE_BODY" | cmp -s - "$NOTICE"; then
  printf '%s' "$NOTICE_BODY" > "$NOTICE"
  if [ "$(id -u)" = 0 ] || [ "$(id -un 2>/dev/null || true)" = "$APP_USER" ]; then
    chown "$APP_USER:$APP_USER" "$NOTICE" 2>/dev/null || true
  fi
  chmod 644 "$NOTICE" 2>/dev/null || true
fi

say "паки LYW выложены из $TAG ($manifests манифестов, $files файлов) → $DEST"
exit 0
