#!/usr/bin/env bash
# deploy.sh — единственный санкционированный способ выкладки Systema Sanscriticum.
#
# Идемпотентен: безопасно запускать повторно. Кодифицирует ритуал из issue #193 —
# ручные выкладки без сброса кэшей/OPcache приводили к «странице со старой разметкой»
# (OPcache на проде работает с validate_timestamps=0, поэтому reload php-fpm ОБЯЗАТЕЛЕН).
#
# Использование (на сервере, из каталога приложения или откуда угодно):
#   sudo bash deploy.sh            # обычный деплой
#   sudo bash deploy.sh --down     # с maintenance-режимом на время миграций
#
# Подробности и первичная настройка: docs/deploy.md

set -euo pipefail

# ── Настройки ────────────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/html}"          # каталог приложения (см. docs/php-8.3-upgrade.md, шаг 3)
BRANCH="${BRANCH:-main}"
SMOKE_URL="${SMOKE_URL:-https://samskrte.ru/}"
DEPLOY_LOG="storage/logs/deploys.log"

USE_DOWN=0
[ "${1:-}" = "--down" ] && USE_DOWN=1

cd "$APP_DIR"

say() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m✖ %s\033[0m\n' "$*"; exit 1; }

# ── 0. Предполётные проверки ─────────────────────────────────────────────────
say "Предполётные проверки в $APP_DIR"
[ -f artisan ] || fail "Здесь нет artisan — APP_DIR указывает не на приложение?"
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
[ "$CURRENT_BRANCH" = "$BRANCH" ] || fail "Ветка $CURRENT_BRANCH, ожидалась $BRANCH"

# Прод-локальные документы: оферту/политику/согласие заменяют на сервере
# руками, мимо git. Пока они не закоммичены в репо, деплой обязан их пережить:
# стэшим на время обновления кода и возвращаем после. Любая ДРУГАЯ грязь —
# по-прежнему стоп-сигнал: это не «известный танец», а чьи-то незафиксированные
# правки, которые reset/pull молча потеряет.
ALLOWED_DIRTY_RE='^public/docs/[^/]+\.pdf$'
DIRTY_FILES=$(git status --porcelain --untracked-files=no | sed 's/^...//')
STASHED=0
if [ -n "$DIRTY_FILES" ]; then
  if echo "$DIRTY_FILES" | grep -qvE "$ALLOWED_DIRTY_RE"; then
    fail "Рабочее дерево грязное (не только public/docs/*.pdf) — сначала разобраться с локальными изменениями (git status)"
  fi
  say "Стэшу прод-локальные PDF (public/docs) на время обновления кода"
  # shellcheck disable=SC2086 — пути прошли allowlist-регэксп, пробелов нет
  git stash push -m "deploy.sh auto-stash $(date '+%F %T')" -- $DIRTY_FILES
  STASHED=1
fi
OLD_COMMIT=$(git rev-parse --short HEAD)

# ── 1. Код ───────────────────────────────────────────────────────────────────
say "git pull --ff-only origin $BRANCH"
git fetch origin
git pull --ff-only origin "$BRANCH"

if [ "$STASHED" = 1 ]; then
  say "Возвращаю прод-локальные PDF из стэша"
  git stash pop || fail "git stash pop конфликтнул — PDF в public/docs изменились и в репозитории. Разбор руками: git status; свежие прод-версии лежат в стэше (git stash list)."
fi
NEW_COMMIT=$(git rev-parse --short HEAD)
if [ "$OLD_COMMIT" = "$NEW_COMMIT" ]; then
  echo "Код не изменился ($NEW_COMMIT) — продолжаю (пересборка кэшей всё равно полезна)."
fi

# ── 2. Зависимости и фронтенд ────────────────────────────────────────────────
say "composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction

say "npm ci && npm run build"
npm ci --silent
npm run build

# Публикуем ассеты Filament (public/{css,js}/filament) — это build-артефакты,
# в git не хранятся (см. .gitignore). Без этого шага после git-деплоя они бы
# отсутствовали/устаревали → рассинхрон версии Livewire и «поле обязательно» на
# входе в админку (реальный инцидент при переезде на новый сервер, июль 2026).
# Livewire-ассеты не публикуем: Livewire 3 отдаёт livewire.js маршрутом.
say "php artisan filament:assets"
php artisan filament:assets

# ── 3. Maintenance (опционально) + миграции ──────────────────────────────────
if [ "$USE_DOWN" = 1 ]; then
  say "php artisan down"
  php artisan down --retry=15 || true
fi

say "Сброс кэшей + миграции"
php artisan optimize:clear
# Кеш Filament-компонентов optimize:clear НЕ трогает (bootstrap/cache/filament/);
# без явного сброса новый виджет/страница ловит ComponentNotFoundException на
# первом же update-запросе (см. docs/deploy.md, гочка LeadCostRangeWidget).
php artisan filament:optimize-clear 2>/dev/null || true
php artisan migrate --force

# ── 4. Прогрев кэшей под прод ────────────────────────────────────────────────
say "Прогрев кэшей (config/route/view + filament)"
php artisan optimize
php artisan filament:optimize 2>/dev/null || true

# ── 5. OPcache: reload php-fpm (КРИТИЧНО — validate_timestamps=0) ───────────
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
say "systemctl reload php${PHP_VER}-fpm (сброс OPcache)"
systemctl reload "php${PHP_VER}-fpm" || fail "Не удалось перезагрузить php${PHP_VER}-fpm — старые вьюхи останутся в OPcache!"

# ── 6. Очереди: рестарт Horizon через supervisor ────────────────────────────
# horizon:terminate на этом проде воркеры НЕ циклит (PID-ы не меняются — они
# продолжают крутить старый код/кеш); работает только supervisorctl restart.
# Фолбэк на terminate — для окружений без supervisor (dev-бокс).
say "Рестарт Horizon"
if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl restart horizon || fail "supervisorctl restart horizon провалился — очереди крутят старый код!"
  sleep 2
  supervisorctl status horizon || true
else
  php artisan horizon:terminate || echo "Horizon не запущен — пропускаю."
fi

# ── 6b. Track C: рестарт АВАРИЙНОГО поллера @zapisi_ORSbot, если он запущен ──
# Тот же случай, что и с Horizon: долгоживущий процесс держит старый код, пока
# его не перезапустить.
#
# Условие именно «сейчас RUNNING», а не «программа известна supervisor'у»:
# `supervisorctl restart` на ОСТАНОВЛЕННОЙ программе её ЗАПУСКАЕТ, а поллер в
# рабочем режиме запускаться не должен — он снимает вебхук и уводит бота с
# штатной дорожки. Программа стоит с autostart=false ровно поэтому.
if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status zapisi-poll 2>/dev/null | grep -q RUNNING; then
  say "Рестарт zapisi-poll (аварийный поллер запущен)"
  supervisorctl restart zapisi-poll || echo "ВНИМАНИЕ: zapisi-poll не перезапустился — апдейты бота могут не приходить."
fi

if [ "$USE_DOWN" = 1 ]; then
  say "php artisan up"
  php artisan up
fi

# ── 7. Смоук-проверка ────────────────────────────────────────────────────────
say "Смоук: $SMOKE_URL"
HTTP_CODE=$(curl -fsS -o /dev/null -w '%{http_code}' "$SMOKE_URL" || echo "000")
[ "$HTTP_CODE" = "200" ] || fail "Смоук провален: $SMOKE_URL вернул $HTTP_CODE"
echo "OK: $SMOKE_URL → 200"

# ── 8. Журнал деплоев ────────────────────────────────────────────────────────
echo "$(date '+%Y-%m-%d %H:%M:%S') ${OLD_COMMIT}..${NEW_COMMIT} php${PHP_VER} by $(whoami)" >> "$DEPLOY_LOG"
say "Деплой завершён: ${OLD_COMMIT} → ${NEW_COMMIT}"
