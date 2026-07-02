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
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  fail "Рабочее дерево грязное — сначала разобраться с локальными изменениями (git status)"
fi
OLD_COMMIT=$(git rev-parse --short HEAD)

# ── 1. Код ───────────────────────────────────────────────────────────────────
say "git pull --ff-only origin $BRANCH"
git fetch origin
git pull --ff-only origin "$BRANCH"
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

# ── 3. Maintenance (опционально) + миграции ──────────────────────────────────
if [ "$USE_DOWN" = 1 ]; then
  say "php artisan down"
  php artisan down --retry=15 || true
fi

say "Сброс кэшей + миграции"
php artisan optimize:clear
php artisan migrate --force

# ── 4. Прогрев кэшей под прод ────────────────────────────────────────────────
say "Прогрев кэшей (config/route/view + filament)"
php artisan optimize
php artisan filament:optimize 2>/dev/null || true

# ── 5. OPcache: reload php-fpm (КРИТИЧНО — validate_timestamps=0) ───────────
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
say "systemctl reload php${PHP_VER}-fpm (сброс OPcache)"
systemctl reload "php${PHP_VER}-fpm" || fail "Не удалось перезагрузить php${PHP_VER}-fpm — старые вьюхи останутся в OPcache!"

# ── 6. Очереди: мягкий рестарт Horizon (supervisor поднимет заново) ─────────
say "php artisan horizon:terminate"
php artisan horizon:terminate || echo "Horizon не запущен — пропускаю."

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
