#!/usr/bin/env bash
# 04-app-deploy.sh — код из GitHub + .env + storage/app + migrate + кэши.
# Требует: deploy key в /root/.ssh (github.com), фазы 02–03 выполнены.
# *** GATE ***: `migrate --force` требует CONFIRM_MIGRATE=yes — на свежем боксе
# схема уже в дампе, миграции нужны только для хвостов после restore.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html/app}"
STAGE="${STAGE:-/srv/migrate}"
REPO_GIT="${REPO_GIT:-git@github.com:gasyoun/Systema-Sanscriticum.git}"
BRANCH="${BRANCH:-main}"
PHP_VER="8.3"
DEPLOY_USER="${DEPLOY_USER:-deploy}"

say() { printf '[app] %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }

say "1/6 клон ${REPO_GIT} (${BRANCH})"
if [ -d "${APP_DIR}/.git" ]; then
    git -C "$APP_DIR" fetch origin --quiet && git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
else
    install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$(dirname "$APP_DIR")"
    sudo -u "$DEPLOY_USER" git clone -b "$BRANCH" "$REPO_GIT" "$APP_DIR"
fi

say "2/6 .env из снапшота (APP_KEY сохранён!)"
install -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 640 "${STAGE}/env.restored" "${APP_DIR}/.env"
grep -q '^APP_KEY=base64' "${APP_DIR}/.env" || { echo "APP_KEY пуст — СТОП" >&2; exit 1; }

say "3/6 storage/app из снапшота (записи уроков, сертификаты)"
if [ -f "${STAGE}/paths.storage_app" ]; then
    SRC_STORAGE=$(cat "${STAGE}/paths.storage_app")
    rsync -a "${SRC_STORAGE}/" "${APP_DIR}/storage/app/"
    say "storage/app синхронизирован из $(du -sh "$SRC_STORAGE" | cut -f1)"
fi

say "4/6 composer install --no-dev"
cd "$APP_DIR"
sudo -u "$DEPLOY_USER" composer install --no-dev --optimize-autoloader --no-interaction

say "5/6 права"
chown -R "$DEPLOY_USER:www-data" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

say "6/6 artisan: migrate/caches/link"
sudo -u "$DEPLOY_USER" php artisan config:clear >/dev/null
if [ "${CONFIRM_MIGRATE:-}" = "yes" ]; then
    sudo -u "$DEPLOY_USER" php artisan migrate --force
else
    say "*** GATE *** migrate пропущен: запусти c CONFIRM_MIGRATE=yes если нужен"
fi
sudo -u "$DEPLOY_USER" php artisan config:cache
sudo -u "$DEPLOY_USER" php artisan route:cache
sudo -u "$DEPLOY_USER" php artisan view:cache
[ -e public/storage ] || sudo -u "$DEPLOY_USER" php artisan storage:link

about_fail=$(php artisan about --json | jq '[.cache, .drivers] | tostring' | grep -ci 'FAIL' || true)
[ "$about_fail" = "0" ] || say "ВНИМАНИЕ: artisan about содержит FAIL-строки — проверь вручную"

say "APP DEPLOY PASS — дальше: 05-services.sh"
