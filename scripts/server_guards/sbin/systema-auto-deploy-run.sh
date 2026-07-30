#!/bin/bash
# systema-auto-deploy-run.sh — авто-деплой origin/main по root-крону (H1933).
#
# MANAGED FILE — installed by scripts/server_guards_apply.sh from
# scripts/server_guards/sbin/systema-auto-deploy-run.sh. Edit the repo copy, not
# the server copy: `php artisan guards:verify` reports any hand-edit as drift.
#
# Контракт (MG, 30-07-2026): деплоить каждые 30 минут без человека, НО после
# каждого деплоя проверять, что сервер остался жив; любой провал ставит
# предохранитель и останавливает БУДУЩИЕ авто-деплои до разбора человеком.
#
#  • flock: два прогона не пересекаются (деплой может идти дольше 30 минут).
#  • Предохранитель storage/auto_deploy.disabled: существует → молча не деплоим.
#    Тревогу шлёт не этот скрипт, а guards:verify (critical) → cabinet:probe →
#    Telegram — то же плечо, что у всех предохранителей H1914.
#  • HEAD == origin/main → тихий выход: cron-шум каждые 30 минут не нужен.
#  • Деплой строго через deploy.sh — единственный санкционированный путь
#    выкладки (ff-only, стоп на грязном дереве, миграции, OPcache, Horizon,
#    смоук, guards:verify — всё там).
#  • Пост-деплойное здоровье проверяется НЕЗАВИСИМО от deploy.sh: смоук ещё раз,
#    MemAvailable, php-fpm/mysql/cron active, Horizon RUNNING.
#  • Отката НЕТ сознательно: migrate --force необратим, откат кода поверх новой
#    схемы опаснее стопа. Провал = предохранитель + громкая тревога, чинит
#    человек. Снять предохранитель: разобраться и удалить файл.
set -uo pipefail

APP_DIR=${SYSTEMA_APP_DIR:-@@APP_DIR@@}
MAX=${SYSTEMA_AUTO_DEPLOY_MAX:-@@AUTO_DEPLOY_MAX_SECONDS@@}
MIN_MB=${SYSTEMA_AUTO_DEPLOY_MIN_MB:-@@AUTO_DEPLOY_MIN_AVAILABLE_MB@@}
SMOKE_URL=${SMOKE_URL:-@@AUTO_DEPLOY_SMOKE_URL@@}
DEPLOY_SH=${SYSTEMA_DEPLOY_SH:-$APP_DIR/deploy.sh}
BREAKER="$APP_DIR/storage/auto_deploy.disabled"
LOCK="$APP_DIR/storage/framework/auto-deploy.lock"

stamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
trip() {
  printf '%s %s\n' "$(stamp)" "$1" >> "$BREAKER"
  echo "$(stamp) BREAKER TRIPPED: $1"
  exit 1
}

exec 9>"$LOCK" 2>/dev/null || exit 0
flock -n 9 || exit 0            # прошлый прогон ещё идёт — молча пропускаем

[ -f "$BREAKER" ] && exit 0     # предохранитель стоит; кричит guards:verify

cd "$APP_DIR" || exit 1
if ! git fetch -q origin; then
  echo "$(stamp) git fetch не прошёл (сеть?) — попробуем в следующем слоте"
  exit 0
fi
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] && exit 0

echo "$(stamp) AUTO-DEPLOY: $(git rev-parse --short "$LOCAL") -> $(git rev-parse --short "$REMOTE")"
timeout -k 30s "${MAX}s" bash "$DEPLOY_SH"
rc=$?
if [ "$rc" -ne 0 ]; then
  trip "deploy.sh завершился с кодом $rc — авто-деплой остановлен; разберитесь и удалите $BREAKER"
fi

# ── Пост-деплойное здоровье: сервер обязан остаться жив ─────────────────────
fails=""
code=$(curl -fsS -o /dev/null -m 30 -w '%{http_code}' "$SMOKE_URL" 2>/dev/null || echo 000)
[ "$code" = "200" ] || fails="$fails smoke:$code"
avail=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
[ "${avail:-0}" -ge "$MIN_MB" ] || fails="$fails mem:${avail}MB<${MIN_MB}MB"
for unit in php@@PHP_VERSION@@-fpm mysql cron; do
  systemctl is-active --quiet "$unit" || fails="$fails unit:$unit"
done
if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl status horizon 2>/dev/null | grep -q RUNNING || fails="$fails horizon"
fi
if [ -n "$fails" ]; then
  trip "деплой прошёл, но сервер нездоров:$fails — авто-деплой остановлен; разберитесь и удалите $BREAKER"
fi

echo "$(stamp) OK: задеплоен $(git rev-parse --short HEAD), health чист (mem ${avail}MB, smoke 200)"
exit 0
