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
#  • Автооткат (MG, 30-07-2026): если деплой провалился ИЛИ здоровье не прошло,
#    И деплой не приносил миграций (git diff по database/migrations/ пуст) —
#    автоматически возвращаемся на прежний коммит (deploy.sh --rollback: тот же
#    конвейер без pull и без миграций), сайт живет на старом коде, человек
#    чинит без спешки. Миграции в деплое → отката НЕТ (migrate --force
#    необратим) — предохранитель + critical, человек нужен срочно.
#  • Предохранитель ставится в ЛЮБОМ провальном исходе — авто-деплои стоят до
#    разбора. Метка [rolled-back] в причине = сайт восстановлен откатом;
#    guards:verify даёт за неё warning вместо critical.
set -uo pipefail

# Debian-cron даёт PATH=/usr/bin:/bin — composer живёт в /usr/local/bin, и
# первый живой цикл 30-07-2026 10:00Z умер на этом с кодом 127 (предохранитель
# сработал штатно). Полный PATH выставляем здесь, а не только в crontab: обёртка
# обязана работать одинаково из cron, руками и из чужого вызова.
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

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

# ── Пост-деплойное здоровье: сервер обязан остаться жив ─────────────────────
# Пишет найденные проблемы в $fails (пусто = здоров).
health_check() {
  fails=""
  local code avail unit
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
  [ -z "$fails" ]
}

# Провал деплоя/здоровья: попытаться автооткат, затем — предохранитель.
# Откат разрешён только без миграций в диапазоне (migrate --force необратим).
fail_deploy() {
  local reason="$1"
  if git diff --name-only "$LOCAL" "$REMOTE" -- database/migrations/ | grep -q .; then
    trip "$reason; в деплое есть миграции — автооткат запрещён, нужен человек СРОЧНО"
  fi
  echo "$(stamp) ROLLBACK: возвращаю $(git rev-parse --short "$LOCAL")"
  if timeout -k 30s "${MAX}s" bash "$DEPLOY_SH" --rollback "$LOCAL" && health_check; then
    trip "[rolled-back] $reason; автоматически откатились на $(git rev-parse --short "$LOCAL"), сайт жив — чинить можно без спешки"
  fi
  trip "$reason; автооткат НЕ помог — сервер требует человека немедленно"
}

echo "$(stamp) AUTO-DEPLOY: $(git rev-parse --short "$LOCAL") -> $(git rev-parse --short "$REMOTE")"
timeout -k 30s "${MAX}s" bash "$DEPLOY_SH"
rc=$?
if [ "$rc" -ne 0 ]; then
  fail_deploy "deploy.sh завершился с кодом $rc"
fi

if ! health_check; then
  fail_deploy "деплой прошёл, но сервер нездоров:$fails"
fi

echo "$(stamp) OK: задеплоен $(git rev-parse --short HEAD), health чист (mem ${avail:-?}MB, smoke 200)"
exit 0
