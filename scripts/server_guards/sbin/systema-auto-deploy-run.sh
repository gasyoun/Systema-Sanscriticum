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
#  • deploy.sh exit 75 = код выложен, guards:verify видит drift managed-файлов
#    (нужен server_guards_apply.sh). НЕ откатывать — issue #1143 / 2026-08-05.
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
#    разбора, НО МЯГКИЕ срабатывания перепроверяют себя сами (H2149).
#    Дефект, который это чинит: предохранитель — файл, а условие, из-за которого
#    он встал, — состояние. Условие лечится (грязный файл стал равен origin/main,
#    когда доехал PR), а файл остаётся, и `[ -f "$BREAKER" ] && exit 0` глушил
#    КАЖДЫЙ следующий тик молча и навсегда. Инцидент 01-08-2026: preflight
#    поймал грязный config/marathon_landing_copy.php в 19:28, тот же текст
#    приехал в git через #1045 минутами позже — предохранитель сторожил уже
#    несуществующую проблему, прод стоял 5 коммитов позади, и снял его человек.
#    Теперь мягкая метка + живое здоровье + прошедшая пауза = снимаем сами и
#    пробуем ещё раз; жёсткое срабатывание (без метки) человека ждёт как ждало.
#    Само-снятие ОГРАНИЧЕНО AUTO_DEPLOY_MAX_AUTO_RETRIES подряд: мигающее
#    условие обязано дойти до человека, а не крутиться вечно.
#  • Severity в guards:verify (H2066 + H2104):
#      [rolled-back]       — сайт восстановлен откатом → warning
#      [blocked-preflight] — HEAD не сдвинулся, health чист (грязное дерево
#                            и т.п.) → warning, не «человеку СРОЧНО»
#      [timeout-alive]     — deploy/rollback timeout (124/137), но health
#                            чист (smoke 200) → warning; Артём не нужен
#      иначе               — critical (прод может быть нездоров)
#  • Каждые 30 минут (даже если HEAD уже = origin/main) обновляет 644-зеркало
#    storage/app/server_guards/crontab-root.installed — чтобы cabinet:probe /
#    guards:verify от www-data видели живой root-крон (H1941: spool 600, bare
#    crontab -l врёт чужим user'ом). deploy.sh и apply.sh пишут то же зеркало.
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
APP_USER=${SYSTEMA_APP_USER:-www-data}

# H2149 — само-перепроверка мягкого предохранителя.
RETRY_AFTER_MIN=${SYSTEMA_AUTO_DEPLOY_RETRY_AFTER_MIN:-@@AUTO_DEPLOY_RETRY_AFTER_MINUTES@@}
MAX_RETRIES=${SYSTEMA_AUTO_DEPLOY_MAX_AUTO_RETRIES:-@@AUTO_DEPLOY_MAX_AUTO_RETRIES@@}
# Счётчик подряд идущих само-снятий. Живёт ОТДЕЛЬНЫМ файлом, а не строкой в
# предохранителе: предохранитель мы удаляем, а серию попыток обязаны помнить
# поверх удаления — иначе мигающее условие сбрасывало бы счётчик само себе и
# крутилось бы вечно. Обнуляется ровно одним событием — успешным деплоем.
RETRIES_FILE="$APP_DIR/storage/framework/auto-deploy-retries"
# Куда уезжает текст снятого предохранителя. След обязан пережить снятие —
# иначе «почему прод не деплоился в 19:30» станет неотвечаемым вопросом.
BREAKER_HISTORY="$APP_DIR/storage/logs/auto_deploy_breaker_history.log"
# H3121: вывод ТОЛЬКО последнего прогона deploy.sh, отдельно от общего лога.
# Нужен ради одной строки в тексте предохранителя — «на каком шаге встали».
# 19-08-2026 предохранитель писал «разобрать npm/vite» при том, что тот же лог
# двумя строками выше говорил `npm skip`, и человек ушёл искать сборку фронта
# вместо троттлинга cgroup. Перезаписывается каждым прогоном: это снимок, а не
# история — история и так лежит в auto_deploy.log.
STAGE_LOG="$APP_DIR/storage/logs/auto_deploy_last_run.log"
# H2305: consecutive silent-exit (lock/fetch) counter; trips breaker after MAX_STALL.
STALL_FILE="$APP_DIR/storage/framework/auto-deploy-stall"
MAX_STALL=${SYSTEMA_AUTO_DEPLOY_MAX_STALL:-5}

stamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }

# Последняя непустая строка вывода deploy.sh — «где именно встали» (H3121).
# Обрезаем: строка едет в текст предохранителя, а его читают в Telegram.
last_stage() {
  local line
  line=$(grep -v '^[[:space:]]*$' "$STAGE_LOG" 2>/dev/null | tail -n 1)
  [ -n "$line" ] || { printf 'deploy.sh не напечатал ничего'; return; }
  printf '%.140s' "$line"
}

# Приписка про троттлинг — только когда он есть. Пустая строка в норме, чтобы
# текст предохранителя не рос впустую.
cgroup_note() {
  local cg
  cg=$(cgroup_over_high) || return 0
  printf '; cron.service НАД memory.high (%s) — деплой ЖЕРТВА троттлинга, а не причина (H3121)' "$cg"
}
trip() {
  printf '%s %s\n' "$(stamp)" "$1" >> "$BREAKER"
  echo "$(stamp) BREAKER TRIPPED: $1"
  exit 1
}

# H2305: increment preflight-stall counter; trip breaker once MAX_STALL is reached.
incr_stall() {
  local reason="$1"
  local count
  count=$(( $(cat "$STALL_FILE" 2>/dev/null || echo 0) + 1 ))
  echo "$count" > "$STALL_FILE" || trip "[blocked-preflight] stall counter write failed — $reason"
  echo "$(stamp) stall $count/$MAX_STALL: $reason"
  [ "$count" -ge "$MAX_STALL" ] && trip "[blocked-preflight] $reason after $count consecutive stalls"
}

# Снимок root-crontab, читаемый www-data (H1941). Fail-open: crontab недоступен
# → просто не трогаем зеркало; probe тогда ругается честно, а не молчит.
refresh_root_crontab_mirror() {
  local dir="$APP_DIR/storage/app/server_guards"
  local mirror="$dir/crontab-root.installed"
  local tmp="$mirror.tmp"
  mkdir -p "$dir" 2>/dev/null || return 0
  if ! crontab -l > "$tmp" 2>/dev/null; then
    rm -f "$tmp"
    return 0
  fi
  mv -f "$tmp" "$mirror"
  chmod 644 "$mirror" 2>/dev/null || true
  chown "root:${APP_USER}" "$mirror" 2>/dev/null || true
}

# ── Пост-деплойное здоровье: сервер обязан остаться жив ─────────────────────
# Пишет найденные проблемы в $fails (пусто = здоров). Определена ДО гейта
# предохранителя (H2149): снятие мягкой метки обязано сперва убедиться, что
# машина сейчас жива, а значит должно уметь звать эту проверку.
# $avail НЕ local — успешная строка лога печатает «mem ${avail}MB», и при
# local она всю жизнь печатала «mem ?MB» (косметика, но врала человеку,
# который читает лог во время разбора).

# ── Троттлинг собственной cgroup: то, чего health_check не видел ────────────
# 19-08-2026 (H3121) прод стоял семь часов при MemAvailable 8.5 ГиБ — то есть
# health_check был зелёным ВСЁ ВРЕМЯ. Память кончилась не на хосте, а в группе
# system.slice/cron.service: демон MadelineProto, унаследовавший её cgroup,
# упёрся в MemoryHigh=2G, и ядро притормозило КАЖДЫЙ процесс группы, включая
# сам деплой (composer 3 с → 16 мин). Хостовая проверка такое не видит
# структурно, поэтому смотрим на бюджет ИМЕННО ТОЙ группы, в которой бежим.
# Печатает «<current>MiB/<high>MiB» и возвращает 0, только когда группа НАД
# порогом; всё остальное (нет cgroup v2, нет потолка) — молча 1, fail-open.
CRON_CGROUP=${SYSTEMA_CRON_CGROUP:-/sys/fs/cgroup/system.slice/cron.service}
cgroup_over_high() {
  local cur high
  cur=$(cat "$CRON_CGROUP/memory.current" 2>/dev/null) || return 1
  high=$(cat "$CRON_CGROUP/memory.high" 2>/dev/null) || return 1
  case "$high" in ''|*[!0-9]*) return 1 ;; esac   # «max» = потолка нет
  case "$cur"  in ''|*[!0-9]*) return 1 ;; esac
  [ "$cur" -lt "$high" ] && return 1
  echo "$((cur / 1048576))MiB/$((high / 1048576))MiB"
}

health_check() {
  fails=""
  local code unit cg
  code=$(curl -fsS -o /dev/null -m 30 -w '%{http_code}' "$SMOKE_URL" 2>/dev/null || echo 000)
  [ "$code" = "200" ] || fails="$fails smoke:$code"
  avail=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
  [ "${avail:-0}" -ge "$MIN_MB" ] || fails="$fails mem:${avail}MB<${MIN_MB}MB"
  cg=$(cgroup_over_high) && fails="$fails cgroup:cron-over-high($cg)"
  for unit in php@@PHP_VERSION@@-fpm mysql cron; do
    systemctl is-active --quiet "$unit" || fails="$fails unit:$unit"
  done
  if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl status horizon 2>/dev/null | grep -q RUNNING || fails="$fails horizon"
  fi
  [ -z "$fails" ]
}

# ── Мягкий предохранитель перепроверяет себя (H2149) ────────────────────────
# Метки, для которых авто-повтор осмыслен. Ровно те же, что guards:verify
# считает warning'ом: сайт при них жив, а причина умеет исчезнуть сама.
# Отсутствие метки = critical = человек; такое НИКОГДА не снимаем сами.
SOFT_TAGS='[blocked-preflight] [blocked-dirty] [timeout-alive] [rolled-back]'

breaker_is_soft() {
  local last tag
  last=$(tail -n 1 "$BREAKER" 2>/dev/null) || return 1
  [ -n "$last" ] || return 1
  for tag in $SOFT_TAGS; do
    case "$last" in *"$tag"*) return 0 ;; esac
  done
  return 1
}

# Возраст предохранителя в минутах по mtime. Пауза нужна, чтобы не молотить
# повтор в том же тике, если условие всё ещё держится.
breaker_age_min() {
  local mtime now
  mtime=$(stat -c %Y "$BREAKER" 2>/dev/null) || { echo 0; return; }
  now=$(date -u +%s)
  echo $(( (now - mtime) / 60 ))
}

auto_retries() {
  local n
  n=$(cat "$RETRIES_FILE" 2>/dev/null) || n=0
  case "$n" in ''|*[!0-9]*) n=0 ;; esac
  echo "$n"
}

# Снять предохранитель, если он мягкий, машина жива, пауза прошла и лимит
# повторов не выбран. Возвращает 0 = сняли, продолжаем прогон.
maybe_clear_breaker() {
  local tries age
  if ! breaker_is_soft; then
    return 1                    # жёсткое срабатывание — только человек
  fi
  tries=$(auto_retries)
  if [ "$tries" -ge "$MAX_RETRIES" ]; then
    # Лимит выбран. НЕ повышаем severity до critical сознательно: причина
    # мягкая (сайт жив), а ложный «хост лежит» — ровно тот класс ошибки, что
    # чинили H2066/H2104. Пишем ОДНУ явную строку, чтобы guards:verify показал
    # человеку, что авто-повтор сдался, и молчания не было.
    if ! grep -q 'auto-retry исчерпан' "$BREAKER" 2>/dev/null; then
      printf '%s %s\n' "$(stamp)" \
        "[blocked-preflight] auto-retry исчерпан ($tries/$MAX_RETRIES) — условие не лечится само, нужен человек" >> "$BREAKER"
    fi
    return 1
  fi
  age=$(breaker_age_min)
  if [ "$age" -lt "$RETRY_AFTER_MIN" ]; then
    return 1                    # ещё рано — подождём следующий слот
  fi
  if ! health_check; then
    echo "$(stamp) auto-retry отложен: health не чист ($fails)"
    return 1                    # в больную машину не возвращаемся никогда
  fi

  mkdir -p "$(dirname "$BREAKER_HISTORY")" 2>/dev/null || true
  {
    printf '%s AUTO-RETRY %s/%s — снимаю предохранитель, причина была:\n' \
      "$(stamp)" "$((tries + 1))" "$MAX_RETRIES"
    sed 's/^/    /' "$BREAKER" 2>/dev/null
  } >> "$BREAKER_HISTORY" 2>/dev/null || true

  echo "$((tries + 1))" > "$RETRIES_FILE" || trip "[stall-counter] auto-retries counter write failed — storage may be full or readonly"
  rm -f "$BREAKER"
  echo "$(stamp) AUTO-RETRY $((tries + 1))/$MAX_RETRIES: мягкий предохранитель снят, health чист — пробуем деплой снова"
  return 0
}

exec 9>"$LOCK" 2>/dev/null || { incr_stall "lock unwritable (storage full or permission error)"; exit 0; }
flock -n 9 || { incr_stall "prior run still holds lock"; exit 0; }   # прошлый прогон ещё идёт

# Зеркало — до breaker/early-exit: probe ходит каждые 15 мин и не должен
# смотреть на вчерашний снимок, даже когда деплоить нечего или деплой стоит.
refresh_root_crontab_mirror

# Предохранитель стоит: мягкий — пробуем снять сами (H2149), жёсткий или
# «ещё рано» — молча выходим, как раньше; кричит guards:verify.
if [ -f "$BREAKER" ]; then
  maybe_clear_breaker || exit 0
fi

cd "$APP_DIR" || exit 1
if ! git fetch -q origin; then
  echo "$(stamp) git fetch не прошёл (сеть?) — попробуем в следующем слоте"
  incr_stall "git fetch failed (network?)"
  exit 0
fi
# H2305: lock acquired + fetch succeeded — reset the preflight-stall counter
rm -f "$STALL_FILE"
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] && exit 0

# Провал деплоя/здоровья: попытаться автооткат, затем — предохранитель.
# Откат разрешён только без миграций в диапазоне (migrate --force необратим).
# $2 = exit code deploy.sh (опционально; 124/137 → timeout/kill).
fail_deploy() {
  local reason="$1"
  local deploy_rc="${2:-1}"
  local head_now
  head_now=$(git rev-parse HEAD 2>/dev/null || echo "")

  # H2066: деплой не стартовал / не сдвинул HEAD, сайт жив → soft breaker.
  # Типичный случай: грязное дерево (не-PDF). Раньше rollback тоже падал на
  # dirty-gate и писал critical «автооткат НЕ помог» при живом HTTP.
  if [ -n "$head_now" ] && [ "$head_now" = "$LOCAL" ] && health_check; then
    trip "[blocked-preflight] $reason; HEAD не сдвинулся ($(git rev-parse --short "$LOCAL")), health чист — разобрать git status / preflight, не паника"
  fi

  if git diff --name-only "$LOCAL" "$REMOTE" -- database/migrations/ | grep -q .; then
    trip "$reason; в деплое есть миграции — автооткат запрещён, нужен человек СРОЧНО"
  fi
  echo "$(stamp) ROLLBACK: возвращаю $(git rev-parse --short "$LOCAL")"
  if timeout -k 30s "${MAX}s" bash "$DEPLOY_SH" --rollback "$LOCAL" 9>&- && health_check; then
    trip "[rolled-back] $reason; автоматически откатились на $(git rev-parse --short "$LOCAL"), сайт жив — чинить можно без спешки"
  fi

  # H2104: timeout (124) / SIGKILL (137) при живом smoke — не «требует Артёма».
  # Инцидент 01-08-2026: vite 1500s → rollback 124 → critical при HTTP 200.
  if health_check && { [ "$deploy_rc" -eq 124 ] || [ "$deploy_rc" -eq 137 ]; }; then
    trip "[timeout-alive] $reason; автооткат не уложился/не помог, но health чист (smoke 200) — сайт жив, Артём не нужен; встали на шаге «$(last_stage)»; MAX=${MAX}s, снять auto_deploy.disabled"
  fi
  if health_check; then
    trip "[timeout-alive] $reason; автооткат НЕ помог, health чист — сайт жив; встали на шаге «$(last_stage)»; разобрать и снять fuse (не host-down)"
  fi
  # Единственная жёсткая ветка: сюда попадаем ровно тогда, когда health НЕ чист.
  # $fails здесь свежий (его выставил последний health_check выше) и с H3121
  # умеет сказать «cgroup:cron-over-high» — до этого критический текст молчал о
  # причине, и человек шёл читать не тот лог.
  trip "$reason; автооткат НЕ помог, health:$fails — сервер требует человека немедленно; встали на шаге «$(last_stage)»$(cgroup_note)"
}

echo "$(stamp) AUTO-DEPLOY: $(git rev-parse --short "$LOCAL") -> $(git rev-parse --short "$REMOTE")"
# tee, а не «> файл»: вывод обязан продолжать течь в auto_deploy.log ЖИВЬЁМ —
# именно по живому потоку 19-08-2026 стало видно, что composer идёт 16 минут.
# Копия в $STAGE_LOG нужна лишь last_stage(). rc берём из PIPESTATUS[0]:
# без этого «$?» вернул бы код tee, то есть всегда 0 (H3121).
# 9>&- закрывает ребёнку lock-fd — та же гигиена, что в schedule/watchdog.
timeout -k 30s "${MAX}s" bash "$DEPLOY_SH" 9>&- 2>&1 | tee "$STAGE_LOG"
rc=${PIPESTATUS[0]}
# 75 = deploy.sh success with managed-guard drift only (#1143). Code is already
# on origin/main; rolling back would re-create the 2026-08-05 loop (deploy →
# GUARDS DRIFT → rollback → retry → fuse). Keep HEAD, skip fail_deploy, still
# require independent health_check below.
if [ "$rc" -eq 75 ]; then
  if ! health_check; then
    fail_deploy "deploy.sh exit 75 (guards drift) and server unhealthy:$fails" 75
  fi
  echo "$(stamp) OK-WITH-GUARDS-DRIFT: code $(git rev-parse --short HEAD) live; apply: bash scripts/server_guards_apply.sh; health clean (mem ${avail:-?}MB, smoke 200)"
  rm -f "$RETRIES_FILE"
  exit 0
fi
if [ "$rc" -ne 0 ]; then
  fail_deploy "deploy.sh завершился с кодом $rc" "$rc"
fi

if ! health_check; then
  fail_deploy "деплой прошёл, но сервер нездоров:$fails" 1
fi

# Успех — единственное событие, обнуляющее серию авто-повторов (H2149).
# Обнулять по «предохранителя нет» было бы неверно: мы его сами и удалили.
rm -f "$RETRIES_FILE"

echo "$(stamp) OK: задеплоен $(git rev-parse --short HEAD), health чист (mem ${avail:-?}MB, smoke 200)"
exit 0
