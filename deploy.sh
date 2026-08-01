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
ROLLBACK_TO=""
while [ $# -gt 0 ]; do
  case "$1" in
    --down) USE_DOWN=1 ;;
    # --rollback <sha> — режим ОТКАТА (H1933, зовёт systema-auto-deploy-run.sh):
    # вместо pull — reset --hard на указанный коммит, миграции НЕ гоняются
    # (migrate --force необратим; автооткат разрешён только когда деплой их
    # не приносил — это проверяет вызывающая обёртка). npm skip'ается, если
    # asset-пути не менялись и public/build на месте (H2104: double-timeout
    # 124 на rollback с полным vite). Остальное (composer, кэши, OPcache,
    # Horizon, смоук) — то же.
    --rollback) shift; ROLLBACK_TO="${1:?--rollback требует SHA}" ;;
    *) printf '\n\033[1;31m✖ Неизвестный аргумент: %s\033[0m\n' "$1"; exit 1 ;;
  esac
  shift
done

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
#
# Исключения (H2066, 01-08-2026):
#  • --rollback: dirty-gate пропускается — reset --hard сам сбрасывает tracked
#    dirty; раньше откат падал на том же preflight, что и forward-деплой
#    (инцидент 31-07: breaker «автооткат НЕ помог» при живом сайте).
#  • Файлы, уже совпадающие с origin/$BRANCH (ручной partial hotfix = будущий
#    commit): сбрасываем к HEAD, pull подтянет их из origin. Реальный
#    расходящийся hotfix — по-прежнему fail.
ALLOWED_DIRTY_RE='^public/docs/[^/]+\.pdf$'
DIRTY_FILES=$(git status --porcelain --untracked-files=no | sed 's/^...//')
STASHED=0
if [ -z "$ROLLBACK_TO" ] && [ -n "$DIRTY_FILES" ]; then
  # Нужен origin, чтобы отличить «уже в main» от «настоящий hotfix».
  git fetch origin
  REAL_DIRTY=""
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if echo "$f" | grep -qE "$ALLOWED_DIRTY_RE"; then
      continue
    fi
    # working tree == origin/$BRANCH for this path → discard to HEAD (safe)
    if git rev-parse --verify -q "origin/${BRANCH}" >/dev/null \
      && git diff --quiet "origin/${BRANCH}" -- "$f" 2>/dev/null; then
      say "Сбрасываю tracked dirty, уже совпадающий с origin/${BRANCH}: $f"
      git checkout HEAD -- "$f"
      continue
    fi
    REAL_DIRTY="${REAL_DIRTY}${REAL_DIRTY:+$'\n'}$f"
  done <<EOF
$DIRTY_FILES
EOF
  if [ -n "$REAL_DIRTY" ]; then
    fail "Рабочее дерево грязное (не только public/docs/*.pdf и не совпадает с origin/${BRANCH}):
$REAL_DIRTY
— сначала разобраться с локальными изменениями (git status)"
  fi
  # Перечитать после auto-discard: остались только PDF (или пусто).
  DIRTY_FILES=$(git status --porcelain --untracked-files=no | sed 's/^...//')
  if [ -n "$DIRTY_FILES" ]; then
    if echo "$DIRTY_FILES" | grep -qvE "$ALLOWED_DIRTY_RE"; then
      fail "Рабочее дерево грязное (не только public/docs/*.pdf) — сначала разобраться с локальными изменениями (git status)"
    fi
    say "Стэшу прод-локальные PDF (public/docs) на время обновления кода"
    # shellcheck disable=SC2086 — пути прошли allowlist-регэксп, пробелов нет
    git stash push -m "deploy.sh auto-stash $(date '+%F %T')" -- $DIRTY_FILES
    STASHED=1
  fi
elif [ -n "$ROLLBACK_TO" ] && [ -n "$DIRTY_FILES" ]; then
  say "Откат: dirty-gate пропущен (reset --hard снимет tracked dirty)"
fi
OLD_COMMIT=$(git rev-parse --short HEAD)

# ── 1. Код ───────────────────────────────────────────────────────────────────
if [ -n "$ROLLBACK_TO" ]; then
  say "ОТКАТ: git reset --hard $ROLLBACK_TO"
  git cat-file -e "${ROLLBACK_TO}^{commit}" 2>/dev/null || fail "Нет такого коммита: $ROLLBACK_TO"
  git reset --hard "$ROLLBACK_TO"
else
  say "git pull --ff-only origin $BRANCH"
  # fetch уже мог пройти на dirty-preflight; повторный — дёшево и идемпотентен
  git fetch origin
  git pull --ff-only origin "$BRANCH"
fi

if [ "$STASHED" = 1 ]; then
  say "Возвращаю прод-локальные PDF из стэша"
  git stash pop || fail "git stash pop конфликтнул — PDF в public/docs изменились и в репозитории. Разбор руками: git status; свежие прод-версии лежат в стэше (git stash list)."
fi
NEW_COMMIT=$(git rev-parse --short HEAD)
# Полные SHA для git diff assets (OLD_COMMIT снят short-ом до pull/reset).
OLD_FULL=$(git rev-parse "$OLD_COMMIT" 2>/dev/null || git rev-parse HEAD)
NEW_FULL=$(git rev-parse HEAD)
if [ "$OLD_COMMIT" = "$NEW_COMMIT" ]; then
  echo "Код не изменился ($NEW_COMMIT) — продолжаю (пересборка кэшей всё равно полезна)."
fi

# ── 2. Зависимости и фронтенд ────────────────────────────────────────────────
say "composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction

# H2104 (01-08-2026): npm ci + vite — главный потребитель wall-clock на автодеплое
# (инцидент 11:00Z: docs-only PR → timeout 1500s → rollback снова vite → 124 →
# critical breaker при живом HTTP). Собираем фронт только если изменились
# asset-пути, нет FORCE_NPM=1, и (для skip) на диске есть manifest.
# Пути: package* / vite / postcss / tailwind / resources/{js,css,css/**}.
need_npm_build() {
  if [ "${FORCE_NPM:-0}" = "1" ]; then
    echo "FORCE_NPM=1 — принудительная пересборка фронта"
    return 0
  fi
  if [ ! -f public/build/manifest.json ]; then
    echo "нет public/build/manifest.json — нужна сборка"
    return 0
  fi
  if [ "$OLD_FULL" = "$NEW_FULL" ]; then
    echo "HEAD не сдвинулся — npm skip (manifest на месте)"
    return 1
  fi
  # Любой path, влияющий на vite output
  if git diff --name-only "$OLD_FULL" "$NEW_FULL" -- \
      package.json package-lock.json \
      vite.config.js vite.config.ts vite.config.mjs \
      postcss.config.js postcss.config.cjs postcss.config.mjs \
      tailwind.config.js tailwind.config.ts tailwind.config.cjs \
      resources/js resources/css \
    | grep -q .; then
    echo "изменились asset-пути — npm ci + build"
    return 0
  fi
  echo "asset-пути не менялись ($OLD_COMMIT..$NEW_COMMIT) — npm skip"
  return 1
}

if need_npm_build; then
  say "npm ci && npm run build"
  npm ci --silent
  npm run build
else
  say "npm skip (assets unchanged; FORCE_NPM=1 to force)"
fi

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
if [ -n "$ROLLBACK_TO" ]; then
  say "ОТКАТ: миграции пропускаются (migrate --force необратим)"
else
  php artisan migrate --force
fi

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

# ── 7b. Ресурсные предохранители ОС (H1914) ─────────────────────────────────
# Только ПРОВЕРКА, никогда не apply: выкладка кода не должна молча менять
# системный конфиг. Предохранители 29-07-2026 живут вне репозитория, и пересборка
# LXC/восстановление из бэкапа сносят их беззвучно — деплой самый частый момент,
# когда об этом можно узнать вовремя.
#
# Зеркало root-crontab (644): cabinet:probe крутится от www-data и не читает
# /var/spool/cron/crontabs/root; без снимка guards:verify под www-data врёт
# «авто-деплой молча не работает» при живой строке в root-кроне.
if [ "$(id -u)" = 0 ]; then
  MIRROR_DIR="$APP_DIR/storage/app/server_guards"
  mkdir -p "$MIRROR_DIR"
  if crontab -l > "$MIRROR_DIR/crontab-root.installed.tmp" 2>/dev/null; then
    mv -f "$MIRROR_DIR/crontab-root.installed.tmp" "$MIRROR_DIR/crontab-root.installed"
    chmod 644 "$MIRROR_DIR/crontab-root.installed"
    chown "root:${APP_USER:-www-data}" "$MIRROR_DIR/crontab-root.installed" 2>/dev/null || true
  fi
fi

say "Предохранители ОС: php artisan guards:verify"
GUARDS_DRIFT=0
php artisan guards:verify || GUARDS_DRIFT=1
if [ "$GUARDS_DRIFT" = 1 ]; then
  printf '\n\033[1;31m%s\033[0m\n' "✖ ПРЕДОХРАНИТЕЛИ ПРОДА РАСХОДЯТСЯ С РЕПОЗИТОРИЕМ (см. список выше)"
  printf '\033[1;31m%s\033[0m\n' "  Вернуть: sudo bash scripts/server_guards_apply.sh"
  printf '\033[1;31m%s\033[0m\n' "  Почему это важно: docs/server-resource-guards.md"
  echo "$(date '+%Y-%m-%d %H:%M:%S') GUARDS DRIFT после ${NEW_COMMIT}" >> "$DEPLOY_LOG"
fi

# ── 8. Журнал деплоев ────────────────────────────────────────────────────────
echo "$(date '+%Y-%m-%d %H:%M:%S') ${OLD_COMMIT}..${NEW_COMMIT} php${PHP_VER} by $(whoami)" >> "$DEPLOY_LOG"
say "Деплой завершён: ${OLD_COMMIT} → ${NEW_COMMIT}"

# Код деплоя ненулевой, если предохранители разошлись: выкладка состоялась (её
# откатывать не за что), но молча пройти мимо пропавшего предохранителя нельзя.
if [ "$GUARDS_DRIFT" = 1 ]; then
  fail "Деплой выложен, но guards:verify недоволен — разберитесь с предохранителями"
fi
