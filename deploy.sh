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

# H4848: деплой идёт от root, а php-fpm обслуживает от www-data. umask 002
# (вместе с setgid на storage/framework/views — см. ensure_views_dir_shared)
# оставляет всё, что root всё-таки записал, группово-записываемым для www-data:
# utime()/touch() разрешён при наличии ПРАВА ЗАПИСИ, не только владения.
umask 002

# ── Настройки ────────────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/html}"          # каталог приложения (см. docs/php-8.3-upgrade.md, шаг 3)
BRANCH="${BRANCH:-main}"
SMOKE_URL="${SMOKE_URL:-https://samskrte.ru/}"
DEPLOY_LOG="storage/logs/deploys.log"

USE_DOWN=0
ROLLBACK_TO=""
DEPLOY_SOFT_FAIL=0
# H4848 (GTD 0A63, 15-09-2026): правки ЭТОГО скрипта действуют в том же цикле,
# когда деплой идёт через auto-deploy обёртку: systema-auto-deploy-run.sh после
# здорового деплоя, чей диапазон трогал deploy.sh, перезапускает его вторым
# прогоном — bash держит прежний inode, сам скрипт через git pull не обновится
# (см. docs/deploy.md, «Правка deploy.sh и следующий прогон»). При ручном
# запуске напрямую (bash deploy.sh) свойство прежнее: правка видна со
# СЛЕДУЮЩЕГО запуска.
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

say()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m✖ %s\033[0m\n' "$*"; exit 1; }
warn() { printf '\n\033[1;33m⚠ %s\033[0m\n' "$*"; }

# H4848: страховка второго уровня. Скомпилированные Blade, созданные root,
# нельзя `touch()`-нуть от www-data (BladeCompiler.php:215 требует владение
# или право записи) — отсюда /admin 500 (17-08, 20-08, 09-09, 14-09-2026).
# Починка постфактум окно только сужает, но не закрывает: файл «сломан» в тот
# же миг, когда его записал root, а запрос мог быть уже в полёте. Поэтому
# нижепрогревающие шаги идут от FPM-пользователя (run_as_app_user) — root их
# вообще не создаёт. Chown остаётся для шагов, которым root действительно нужен
# (guards:verify), и как страховка на будущее.
chown_compiled_views() {
  say "chown compiled views → ${APP_USER:-www-data}"
  chown -R "${APP_USER:-www-data}:${APP_USER:-www-data}" \
    "$APP_DIR/storage/framework/views" \
    || warn "chown compiled views failed — /admin may 500 on next Blade recompile"
}

# H4848: прогрев кэшей ОТ ИМЯ FPM-пользователя. Приоритетное искусство:
# ops/migrate/debian13/04-app-deploy.sh делает `sudo -u $DEPLOY_USER php artisan
# view:cache` — та же идея, здесь обобщена. Возврат к root (или не-root запуск
# локально/dev) деградирует до обычного вызова: helper идемпотентен.
run_as_app_user() {
  local u="${APP_USER:-www-data}"
  if [ "$(id -u)" = 0 ] && [ "$u" != "root" ] && command -v runuser >/dev/null 2>&1; then
    runuser -u "$u" -- "$@"
  else
    "$@"
  fi
}

# H4848: setgid + владелец на каталог вьюх и снятие root-владения с
# bootstrap/cache. Без этого `run_as_app_user php artisan optimize` упадёт:
# старые config.php/routes-v7.php — root:644, www-data их не перезапишет
# (optimize:clear выше их удаляет, но blade-icons.php и filament/panels/*
# остаются). chown --from=root меняет ТОЛЬКО root-овые записи и не трогает
# группу webteam, на которой держатся соседние каталоги.
ensure_views_dir_shared() {
  local dir="$APP_DIR/storage/framework/views" cache="$APP_DIR/bootstrap/cache"
  if [ -d "$dir" ]; then
    chown "${APP_USER:-www-data}:${APP_USER:-www-data}" "$dir" 2>/dev/null || true
    chmod 2770 "$dir" 2>/dev/null || warn "не удалось выставить setgid на $dir"
  fi
  if [ -d "$cache" ]; then
    chown -R --from=root "${APP_USER:-www-data}" "$cache" 2>/dev/null \
      || warn "не удалось снять root-владение с $cache — прогрев кэшей от www-data может упасть"
  fi
}

# H4848: гард, которого требовала миссия — деплой, оставивший root-овый
# скомпилированный view, это ПРОВАЛЬНЫЙ деплой, а не предупреждение. Зовётся
# ПОСЛЕ последнего chown_compiled_views (H3194: `fail` — это exit 1, поэтому
# никогда до починки). Тот же инвариант непрерывно проверяет cabinet:probe
# (сторож */15) от www-data — deploy-гард ловит регресс в момент выкладки.
assert_no_root_views() {
  local dir="$APP_DIR/storage/framework/views" stray
  [ -d "$dir" ] || return 0
  stray=$(find "$dir" -type f ! -user "${APP_USER:-www-data}" 2>/dev/null | head -5 || true)
  if [ -n "$stray" ]; then
    fail "compiled views не принадлежат ${APP_USER:-www-data} — Filament /admin отдаст 500 на следующем recompile:
$stray
  Вернуть: chown -R ${APP_USER:-www-data}:${APP_USER:-www-data} $dir"
  fi
}

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
    # пути прошли allowlist-регэксп, пробелов нет
    # shellcheck disable=SC2086
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
say "composer check-platform-reqs (prod runtime)"
composer check-platform-reqs --no-dev

# H2104 (01-08-2026): npm ci + vite — главный потребитель wall-clock на автодеплое
# (инцидент 11:00Z: docs-only PR → timeout 1500s → rollback снова vite → 124 →
# critical breaker при живом HTTP). Собираем фронт только если изменились
# asset-пути, нет FORCE_NPM=1, и (для skip) на диске есть manifest.
# Пути: package* / vite / postcss / tailwind / resources/{js,css} / resources/views.
# resources/views обязателен в списке (фикс 02-10-2026, «сплошной текст» на
# /prepodavately): Tailwind v4 сканирует blade-вьюхи (@source "../views"),
# PR только с вьюхами меняет CSS-бандл, и skip npm оставлял на проде старый
# бандл — новые классы не стилизованы.
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
      resources/views \
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

# ── 3. Webhook-preflight + maintenance + миграции ───────────────────────────────
say "Сброс кэшей"
php artisan optimize:clear
# Кеш Filament-компонентов optimize:clear НЕ трогает (bootstrap/cache/filament/);
# без явного сброса новый виджет/страница ловит ComponentNotFoundException на
# первом же update-запросе (см. docs/deploy.md, гочка LeadCostRangeWidget).
php artisan filament:optimize-clear 2>/dev/null || { warn "filament:optimize-clear failed — Filament component cache may be stale"; DEPLOY_SOFT_FAIL=1; }

# На rollback команды может ещё не быть в старом коммите. Обычный деплой
# проверяем ДО maintenance-mode и необратимых миграций.
if [ -z "$ROLLBACK_TO" ]; then
  say "Проверка секретов webhook"
  php artisan deploy:webhook-preflight

  # H3311: прод не выкладывается с небезопасной сессионной кукой —
  # SESSION_SECURE_COOKIE обязан быть true (unset теперь тоже true благодаря
  # дефолту в config/session.php, но явное false/мусор останавливает деплой).
  # Пустой TRUSTED_PROXIES в проде даёт warning (не блок). Rollback не блочим.
  say "Проверка конфиг-безопасности (SESSION_SECURE_COOKIE)"
  php artisan deploy:config-preflight
fi

if [ "$USE_DOWN" = 1 ]; then
  say "php artisan down"
  php artisan down --retry=15 || { warn "artisan down --retry=15 failed — maintenance page may not have loaded"; DEPLOY_SOFT_FAIL=1; }
fi

if [ -n "$ROLLBACK_TO" ]; then
  say "ОТКАТ: миграции пропускаются (migrate --force необратим)"
else
  php artisan migrate --force
fi

# ── 4. Прогрев кэшей под прод ────────────────────────────────────────────────
say "Прогрев кэшей (config/route/view + filament) от ${APP_USER:-www-data}"
# H4848: прогрев идёт ОТ FPM-ПОЛЬЗОВАТЕЛЯ, не от root. Раньше здесь стоял
# root-овый `artisan optimize` — именно он компилировал Blade как root:755, и
# php-fpm не мог `touch()`-нуть вьюху (BladeCompiler.php:215), отсюда /admin 500
# в окне до chown (17-08, 20-08, 09-09, 14-09-2026). От www-data вьюхи сразу
# создаются правильным владельцем — окна больше нет, а не «уже».
ensure_views_dir_shared
run_as_app_user php artisan optimize
run_as_app_user php artisan filament:optimize 2>/dev/null || { warn "filament:optimize failed — Filament caches not warmed"; DEPLOY_SOFT_FAIL=1; }

# ── 4b. LYW-паки (H7069) ─────────────────────────────────────────────────────
# Девять zan1-паков закоммичены в resources/data/lesson_packs и читаются
# оттуда (config('lyw.packs_path')). Если LYW_ENABLED=true, а у включённой
# группы нет manifest.json/пак битый — это провальный деплой, а не тихие 404
# студентам. На rollback команды может не быть в старом коммите — пропускаем.
if [ -z "$ROLLBACK_TO" ]; then
  say "LYW packs: php artisan lyw:verify-packs"
  php artisan lyw:verify-packs || fail "LYW packs неполны при LYW_ENABLED=true — включённые группы остались бы без контента. Источник: resources/data/lesson_packs (tracked)"
fi

# Страховка: шаги ниже (cabinet:probe от www-data, guards:verify от root) могут
# дособрать вьюхи. Chown идемпотентен и дёшев.
chown_compiled_views

# ── 5. OPcache: reload php-fpm (КРИТИЧНО — validate_timestamps=0) ───────────
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
say "systemctl reload php${PHP_VER}-fpm (сброс OPcache)"
systemctl reload "php${PHP_VER}-fpm" || fail "Не удалось перезагрузить php${PHP_VER}-fpm — старые вьюхи останутся в OPcache!"

# ── 6. Очереди: рестарт Horizon через supervisor ────────────────────────────
# horizon:terminate на этом проде воркеры НЕ циклит (PID-ы не меняются — они
# продолжают крутить старый код/кеш); работает только supervisorctl restart.
# Фолбэк на terminate — для окружений без supervisor (dev-бокс).
#
# Рестарт ограничен по времени: `supervisorctl restart` ждёт stopwaitsecs
# (3600 с), а простаивающий воркер redis-long порой не выходит на SIGTERM —
# деплой висел, Horizon стоял в STOPPING, бот и рассылки молчали
# (19-10 и 22-10-2026, оба раза — ручной kill). Ждём, пока идёт хоть одна
# зарезервированная задача (до HORIZON_STOP_MAX_SECONDS — потолок задачи
# timeout=600 + запас), без задач — HORIZON_STOP_IDLE_SECONDS; потом добиваем
# процессы Horizon (вместе с осиротевшими воркерами) и стартуем заново.
HORIZON_STOP_IDLE_SECONDS=${HORIZON_STOP_IDLE_SECONDS:-30}
HORIZON_STOP_MAX_SECONDS=${HORIZON_STOP_MAX_SECONDS:-660}
# Только сами php-процессы Horizon — не shell/ssh, где «artisan horizon» в тексте команды.
HORIZON_PROC_RE='^[^ ]*php[0-9.]* [^ ]*artisan horizon'

horizon_reserved_jobs() {
  command -v redis-cli >/dev/null 2>&1 || { echo 1; return; }
  local total=0 key n
  for key in $(redis-cli --scan --pattern '*queues:*:reserved' 2>/dev/null); do
    n=$(redis-cli zcard "$key" 2>/dev/null || echo 0)
    total=$((total + ${n:-0}))
  done
  echo "$total"
}

restart_horizon_bounded() {
  supervisorctl stop horizon >/dev/null 2>&1 &
  local stopper=$! waited=0
  while pgrep -f "$HORIZON_PROC_RE" >/dev/null 2>&1; do
    if [ "$waited" -ge "$HORIZON_STOP_MAX_SECONDS" ] \
      || { [ "$waited" -ge "$HORIZON_STOP_IDLE_SECONDS" ] && [ "$(horizon_reserved_jobs)" -eq 0 ]; }; then
      warn "Horizon не остановился за ${waited} с — добиваю процессы"
      pkill -TERM -f "$HORIZON_PROC_RE" || true
      sleep 5
      pkill -KILL -f "$HORIZON_PROC_RE" || true
      break
    fi
    sleep 5
    waited=$((waited + 5))
  done
  wait "$stopper" 2>/dev/null || true
  supervisorctl start horizon || fail "supervisorctl start horizon провалился — очереди стоят!"
}

say "Рестарт Horizon"
if command -v supervisorctl >/dev/null 2>&1; then
  restart_horizon_bounded
  sleep 2
  supervisorctl status horizon || true
else
  php artisan horizon:terminate || echo "Horizon не запущен — пропускаю."
fi

# H3121: у надзора за демоном MadelineProto та же болезнь, что у Horizon —
# долгоживущий CLI-процесс держит код, загруженный при старте, и без рестарта
# крутил бы его вечно. try-restart, а не restart: на машине, где юнита нет
# (dev-бокс, свежая установка до server_guards_apply.sh), это тихий no-op.
# Окно без демона — ~10 с (TimeoutStopSec); заход cron в это окно поднимет
# демона под кроном, и следующий заход надзора его погасит. Само лечится.
if command -v systemctl >/dev/null 2>&1; then
  systemctl try-restart systema-madeline-daemon.service 2>/dev/null \
    && echo "systema-madeline-daemon перезапущен (свежий код надзора)" \
    || echo "systema-madeline-daemon не запущен — пропускаю."
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

# ── 6c. Track C: рестарт поллера студенческого бота telegram-student-poll, если он запущен ──
# Тот же случай, что и с Horizon (H5051, 17-09-2026): долгоживущий CLI-процесс
# держит код, загруженный при старте, и после деплоя продолжает крутить СТАРЫЙ
# код в памяти. Поллер сам обновляется раз в час (max-lifetime 3600 с), но до
# этого часа пред-деплойный класс успевает писать ошибки в лог.
#
# Условие именно «сейчас RUNNING», а не «программа известна supervisor'у»:
# сам поллер штатно работает (autostart=true), но его могут осознанно
# остановить — возврат на вебхук: `php artisan telegram:webhooks --set` +
# `supervisorctl stop telegram-student-poll`. `supervisorctl restart` на
# ОСТАНОВЛЕННОЙ программе её ПОДНИМАЕТ, то есть молча отменил бы это решение.
if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status telegram-student-poll 2>/dev/null | grep -q RUNNING; then
  say "Рестарт telegram-student-poll (поллер студента запущен)"
  supervisorctl restart telegram-student-poll || echo "ВНИМАНИЕ: telegram-student-poll не перезапустился — бот студента держит пред-деплойный код."
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

# Public smoke can stay 200 while /dvaram 500s (16-08-2026 23:30 UTC:
# homepage fine, cabinet:probe critical on missing club_memberships.tier_code).
# Soft-only findings still exit 0 — do not revive the #1143 rollback loop.
say "Смоук кабинета: cabinet:probe --fail-on-critical --no-alert (от ${APP_USER:-www-data})"
# H4848: проба идёт ОТ WWW-DATA — тем же пользователем, что обслуживает
# запросы. Это не косметика: 14-09-2026 проба от root была зелёной, пока
# /admin отдавал 500, — root-овый `touch()` проба от root не видит
# структурно, и класс «root-овые вьюхи» ею не ловился. От www-data она и
# компилирует вьюхи правильным владельцем, и видит реальное состояние
# (+ собственный views-ownership check, H4848).
# `fail` is `exit 1` — if it runs first, the chown below never happens
# (19-08-2026 21:01Z и 20-08 SOS: probe --fail-on-critical умер на
# tmpfs-cap/backup-fresh, оставив 8 root-овых вьюх — H3194).
# --no-alert: deploy retries must not SOS; watchdog */15 is the mouth (H3197).
# --fail-on-critical is HTTP/cabinet only (host guards no longer fail deploy).
run_as_app_user php artisan cabinet:probe --fail-on-critical --no-alert
probe_rc=$?
chown_compiled_views
[ "$probe_rc" = 0 ] || fail "cabinet:probe: critical после деплоя — кабинет нездоров"

# ── 7b. Контент уроков LYW: паки из пина (H7069) ────────────────────────────
# `storage/` не в git: девять паков зан1 залиты на .92 18-10-2026 руками, и
# первое же восстановление storage, пересборка бокса или второй сервер
# оставляют конфиг с LYW_ENABLED=true без содержимого — вкладка живёт, уроки
# отдают 404. Шаг идемпотентен (второй прогон — no-op по itemize-changes),
# офлайновый (git archive по тегу из локального зеркала, без сети) и объявляет
# расхождения unified diff'ом ДО записи.
#
# Почему сбой шага не `fail`: exit 1 завернул бы хороший код в откат (#1143,
# та же причина, что у баннера managed-дрейфа). Громкий рот — guards:verify:
# пропажу пака включённой группы он называет critical (lyw-packs, H7069), а
# его и зовёт следующий раздел.
say "Контент LYW: паки зан1 из пина"
LYW_PACKS_RC=0
set +e
bash "$APP_DIR/scripts/server_guards/sbin/lesson-packs-sync.sh" 2>&1 | tee -a "$DEPLOY_LOG"
LYW_PACKS_RC=${PIPESTATUS[0]}
set -e
if [ "$LYW_PACKS_RC" != 0 ]; then
  echo "$(date '+%Y-%m-%d %H:%M:%S') LYW PACKS rc=${LYW_PACKS_RC} после ${NEW_COMMIT} — паки не выложены из пина" >> "$DEPLOY_LOG"
  printf '\n\033[1;31m%s\033[0m\n' "✖ ПАКИ LYW НЕ ВЫЛОЖЕНЫ (код $LYW_PACKS_RC) — см. строки выше"
  printf '\033[1;31m%s\033[0m\n' "  Код жив; пропажу паков у включённой группы назовёт guards:verify ниже (critical lyw-packs)"
fi

# ── 7c. Ресурсные предохранители ОС (H1914) ─────────────────────────────────
# Проверка, затем ОБЪЯВЛЕННЫЙ apply (H7060): выкладка кода не должна молча менять
# системный конфиг, поэтому сначала печатается unified diff расходящихся файлов,
# и только потом идёт идемпотентный server_guards_apply.sh (см. 7c). До H7060
# здесь была только проверка, и дрейф от легитимного коммита жил вечно.
# Предохранители 29-07-2026 живут вне репозитория, и пересборка LXC/восстановление
# из бэкапа сносят их беззвучно — деплой самый частый момент, когда об этом можно
# узнать вовремя (и единственный, который случается без человека).
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
# Последний root-овый artisan в этом скрипте: 17-08 07:38Z оставил 8 root-овых
# вьюх после probe+guards даже с пост-probe chown.
chown_compiled_views
# H4848: гард, которого требовала миссия. Деплой, оставивший root-овый
# скомпилированный view, — ПРОВАЛЬНЫЙ деплой, а не предупреждение в логе,
# которое никто не читает. Стоит ПОСЛЕ последнего chown (H3194: `fail` = exit 1).
assert_no_root_views
# ── 7d. Drift managed-файлов: объявить (dry-run diff) → применить → перепроверить
# H7060, инцидентная строка 9 (18-10-2026): легитимный коммит правил managed-файл,
# verify честно ловил расхождение, apply не звался НИКОГДА — баннер «Вернуть:
# sudo bash ...» висел, пока человек не прочтёт; 26 алертов за 36 часов ничего не
# починили, а авто-деплой-обёртка крутила повторы вокруг неподвижного дрейфа.
# Здесь расхождение закрывается тем же деплоем, но не молча: сначала печатается
# unified diff всех расходящихся файлов (деплой обязан объявлять каждое системное
# изменение), затем идёт санкционированный идемпотентный server_guards_apply.sh —
# второй прогон подряд ничего не меняет. Не root / apply упал / drift остался →
# баннер ниже срабатывает как раньше, exit-семантика #1143 не тронута.
if [ "$GUARDS_DRIFT" = 1 ]; then
  MANAGED_APPLY="$APP_DIR/scripts/server_guards/sbin/managed-guards-apply-on-deploy.sh"
  if [ -f "$MANAGED_APPLY" ]; then
    say "Managed guards: dry-run diff → apply (H7060)"
    set +e
    bash "$MANAGED_APPLY" 2>&1 | tee -a "$DEPLOY_LOG"
    MANAGED_RC=${PIPESTATUS[0]}
    set -e
    if [ "$MANAGED_RC" = 0 ]; then
      GUARDS_DRIFT=0
      echo "$(date '+%Y-%m-%d %H:%M:%S') MANAGED APPLY OK после ${NEW_COMMIT} — drift закрыт этим деплоем" >> "$DEPLOY_LOG"
    else
      echo "$(date '+%Y-%m-%d %H:%M:%S') MANAGED APPLY rc=${MANAGED_RC} после ${NEW_COMMIT} — drift остался" >> "$DEPLOY_LOG"
    fi
  else
    warn "нет $MANAGED_APPLY — apply внутри деплоя недоступен, drift остаётся видимым (H7060)"
  fi
fi

if [ "$GUARDS_DRIFT" = 1 ]; then
  printf '\n\033[1;31m%s\033[0m\n' "✖ ПРЕДОХРАНИТЕЛИ ПРОДА РАСХОДЯТСЯ С РЕПОЗИТОРИЕМ (см. список выше)"
  printf '\033[1;31m%s\033[0m\n' "  Вернуть: sudo bash scripts/server_guards_apply.sh"
  printf '\033[1;31m%s\033[0m\n' "  Почему это важно: docs/server-resource-guards.md"
  echo "$(date '+%Y-%m-%d %H:%M:%S') GUARDS DRIFT после ${NEW_COMMIT}" >> "$DEPLOY_LOG"
fi

# ── 8. Журнал деплоев ────────────────────────────────────────────────────────
echo "$(date '+%Y-%m-%d %H:%M:%S') ${OLD_COMMIT}..${NEW_COMMIT} php${PHP_VER} by $(whoami)" >> "$DEPLOY_LOG"
say "Деплой завершён: ${OLD_COMMIT} → ${NEW_COMMIT}"

# Issue #1143: managed-file drift after a successful code deploy must NOT look
# like a failed deploy. Exit 1 made systema-auto-deploy-run.sh roll back the good
# commit in a loop until the soft fuse burned (2026-08-05). Exit **0** so both the
# pre-fix wrapper on prod and the post-apply wrapper keep HEAD. Loud stderr +
# deploys.log "GUARDS DRIFT" remain; guards:verify / cabinet:probe still alert
# until `bash scripts/server_guards_apply.sh`. Optional exit 75 is still accepted
# by the wrapper if a future change reintroduces a distinct code.
if [ "$GUARDS_DRIFT" = 1 ]; then
  printf '\n\033[1;33m%s\033[0m\n' "⚠ Деплой выложен (код жив), но guards:verify недоволен — это НЕ откат"
  printf '\033[1;33m%s\033[0m\n' "  Следующий шаг: sudo bash scripts/server_guards_apply.sh && php artisan guards:verify"
  printf '\033[1;33m%s\033[0m\n' "  Exit 0 (issue #1143) — auto-deploy must not rollback managed-file drift"
  exit 0
fi

# H2305: soft-step failures exit non-zero so systema-auto-deploy-run.sh can
# distinguish a clean deploy from one with degraded cache/maintenance state.
if [ "$DEPLOY_SOFT_FAIL" = 1 ]; then
  printf '\n\033[1;31m%s\033[0m\n' "✖ One or more optional deploy steps failed — see ⚠ warnings above"
  exit 1
fi
