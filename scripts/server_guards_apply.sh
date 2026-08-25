#!/usr/bin/env bash
# server_guards_apply.sh — вернуть на машину ВСЕ ресурсные предохранители прода.
#
# Зачем этот файл существует. 29-07-2026 на прод поставили предохранители,
# которые закрыли класс аварий, дважды за неделю подвешивавших samskrte.ru
# (docs/server-resource-guards.md). Все они жили ТОЛЬКО на машине: пересборка
# LXC, восстановление из бэкапа или переезд — и они исчезают МОЛЧА, без единой
# ошибки, а авария возвращается в прежнем виде. Этот скрипт — их источник правды,
# `php artisan guards:verify` — громкая проверка, что они на месте.
#
# Идемпотентен: второй прогон подряд НИЧЕГО не меняет и печатает только «ok».
# Значения (числа памяти, пороги, таймауты) живут в scripts/server_guards.conf и
# только там; шаблоны в scripts/server_guards/ несут @@ПОДСТАНОВКИ@@.
#
# Использование (на сервере, от root):
#   sudo bash scripts/server_guards_apply.sh                 # применить
#   sudo bash scripts/server_guards_apply.sh --dry-run        # только показать, что изменится
#   sudo bash scripts/server_guards_apply.sh --diff           # то же + unified diff
#   sudo bash scripts/server_guards_apply.sh --no-install     # не ставить пакеты
#   sudo bash scripts/server_guards_apply.sh --restart-supervisor
#
# ДЕПЛОЙ ЭТОГО НЕ ЗОВЁТ. deploy.sh делает только `guards:verify` — выкладка кода
# не должна молча менять системный конфиг.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TPL_ROOT="$SCRIPT_DIR/server_guards"
CONF_FILE="${SERVER_GUARDS_CONF:-$SCRIPT_DIR/server_guards.conf}"

DRY_RUN=0
SHOW_DIFF=0
INSTALL_PACKAGES=1
RESTART_SUPERVISOR=0

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run|-n) DRY_RUN=1 ;;
    --diff) SHOW_DIFF=1 ;;
    --no-install) INSTALL_PACKAGES=0 ;;
    --restart-supervisor) RESTART_SUPERVISOR=1 ;;
    -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Неизвестный аргумент: $1" >&2; exit 2 ;;
  esac
  shift
done

BOLD=$(printf '\033[1m'); RED=$(printf '\033[1;31m'); GRN=$(printf '\033[1;32m')
YLW=$(printf '\033[1;33m'); CYA=$(printf '\033[1;36m'); OFF=$(printf '\033[0m')
say()  { printf '\n%s▶ %s%s\n' "$CYA" "$*" "$OFF"; }
ok()   { printf '  %sok%s      %s\n' "$GRN" "$OFF" "$*"; }
chg()  { printf '  %schanged%s %s\n' "$YLW" "$OFF" "$*"; }
warn() { printf '  %swarn%s    %s\n' "$YLW" "$OFF" "$*"; }
die()  { printf '\n%s✖ %s%s\n' "$RED" "$*" "$OFF" >&2; exit 1; }

[ -f "$CONF_FILE" ] || die "нет файла значений: $CONF_FILE"
[ -d "$TPL_ROOT" ] || die "нет каталога шаблонов: $TPL_ROOT"
if [ "$DRY_RUN" = 0 ] && [ "$(id -u)" != 0 ]; then
  die "нужен root (или --dry-run): sudo bash $0"
fi

# ── Значения ────────────────────────────────────────────────────────────────
declare -A G=()
lineno=0
while IFS= read -r line || [ -n "$line" ]; do
  lineno=$((lineno + 1))
  case "$line" in ''|'#'*|[[:space:]]*) continue ;; esac
  case "$line" in *=*) ;; *) die "$CONF_FILE:$lineno — не пара KEY=value: $line" ;; esac
  key=${line%%=*}
  val=${line#*=}
  val=${val%$'\r'}
  # Кавычки снимаем, но подстановок не допускаем: файл читают и bash, и PHP —
  # разойдись они в трактовке $ или обратных кавычек, «одно место для чисел»
  # перестало бы быть одним.
  case "$val" in
    '"'*'"') val=${val#\"}; val=${val%\"} ;;
  esac
  # Одиночный «$» разрешён — он есть в регулярках earlyoom (^php[0-9.]*$).
  # Запрещены ${…}, $(…) и обратные кавычки: файл разбирается построчно и НИКОГДА
  # не source-ится, так что они всё равно не сработали бы, но выглядели бы
  # работающими — а PHP-половина трактует их так же буквально.
  case "$val" in
    *'${'*|*'$('*|*'`'*) die "$CONF_FILE:$lineno — подстановки в значении запрещены: $key" ;;
  esac
  G[$key]="$val"
done < "$CONF_FILE"

need() { [ -n "${G[$1]:-}" ] || die "$CONF_FILE: не задан $1"; printf '%s' "${G[$1]}"; }
for k in APP_DIR APP_USER PHP_VERSION PHP_BIN SCHEDULE_MAX_SECONDS \
         CRON_MEMORY_HIGH CRON_MEMORY_MAX CRON_TASKS_MAX \
         SUPERVISOR_MEMORY_HIGH SUPERVISOR_MEMORY_MAX LIMIT_NOFILE \
         PHP_CLI_MEMORY_LIMIT FPM_MAX_CHILDREN FPM_MAX_REQUESTS \
         AUTO_DEPLOY_SCHEDULE AUTO_DEPLOY_MAX_SECONDS \
         AUTO_DEPLOY_MIN_AVAILABLE_MB AUTO_DEPLOY_SMOKE_URL \
         AUTO_DEPLOY_RETRY_AFTER_MINUTES AUTO_DEPLOY_MAX_AUTO_RETRIES \
         MADELINE_MEMORY_HIGH MADELINE_MEMORY_MAX MADELINE_TASKS_MAX \
         MADELINE_DAEMON_MAX_RSS_MB MADELINE_DAEMON_MAX_FDS \
         MADELINE_DAEMON_MAX_AGE_HOURS MADELINE_DAEMON_CHECK_SECONDS \
         SCHEDULER_STAMP_MAX_MINUTES \
         TMP_TMPFS_SIZE TMP_TMPFS_MODE TMP_AGE_DAYS \
         BACKUP_MAX_AGE_DAYS BACKUP_REQUIRE_OFFSITE BACKUP_MIN_ARCHIVE_MB; do
  need "$k" >/dev/null
done

APP_DIR=${G[APP_DIR]}
APP_USER=${G[APP_USER]}
PHP_VERSION=${G[PHP_VERSION]}

render() { # render <template-path> → stdout
  local body
  body=$(cat "$1")
  local k
  for k in "${!G[@]}"; do
    body=${body//@@$k@@/${G[$k]}}
  done
  case "$body" in
    *@@*) die "$1: остались неподставленные @@…@@ — нет значения в $CONF_FILE" ;;
  esac
  printf '%s\n' "$body"
}

# ── Бэкап: каталог создаётся ЛЕНИВО, только если что-то реально меняется ────
STAMP=$(date -u '+%Y%m%dT%H%M%SZ')
BACKUP_DIR="/root/preflight-backup-$STAMP"
CHANGED=()
backup() { # backup <existing-path>
  [ -e "$1" ] || return 0
  [ "$DRY_RUN" = 1 ] && return 0
  local dest="$BACKUP_DIR$1"
  mkdir -p "$(dirname "$dest")"
  cp -a "$1" "$dest"
}

install_file() { # install_file <template-rel> <dest> <mode>
  local tpl="$TPL_ROOT/$1" dest="$2" mode="$3" tmp
  [ -f "$tpl" ] || die "нет шаблона $tpl"
  tmp=$(mktemp)
  render "$tpl" > "$tmp"
  if [ -f "$dest" ] && cmp -s "$tmp" "$dest"; then
    local cur_mode
    cur_mode=$(stat -c '%a' "$dest")
    if [ "$cur_mode" = "$mode" ]; then
      rm -f "$tmp"; ok "$dest"; return 0
    fi
  fi
  if [ "$SHOW_DIFF" = 1 ]; then
    diff -u "$dest" "$tmp" 2>/dev/null | sed 's/^/      /' || true
  fi
  if [ "$DRY_RUN" = 1 ]; then
    rm -f "$tmp"; chg "$dest (dry-run)"; CHANGED+=("$dest"); return 0
  fi
  backup "$dest"
  mkdir -p "$(dirname "$dest")"
  install -m "$mode" "$tmp" "$dest"
  rm -f "$tmp"
  chg "$dest"
  CHANGED+=("$dest")
}

ensure_directive() { # ensure_directive <file> <key> <value> <sep> — правка на месте, файл чужой
  local file="$1" key="$2" value="$3" sep="${4:- = }" line
  [ -f "$file" ] || { warn "$file отсутствует — пропускаю $key"; return 0; }
  line="$key$sep$value"
  if grep -qxF "$line" "$file"; then ok "$file :: $line"; return 0; fi
  if [ "$DRY_RUN" = 1 ]; then chg "$file :: $line (dry-run)"; CHANGED+=("$file"); return 0; fi
  backup "$file"
  if grep -qE "^[[:space:]]*$key[[:space:]]*=" "$file"; then
    # Заменяем ВСЕ активные вхождения: иначе последнее продолжит выигрывать и
    # правка окажется декоративной. Закомментированные примеры дистрибутива не
    # трогаем — они документация, а не значение.
    sed -i -E "s|^[[:space:]]*$key[[:space:]]*=.*|$line|" "$file"
  else
    printf '%s\n' "$line" >> "$file"
  fi
  chg "$file :: $line"
  CHANGED+=("$file")
}

# ── 1. Пакеты ───────────────────────────────────────────────────────────────
say "Пакеты предохранителей (earlyoom, rsyslog, sysstat, util-linux/flock)"
MISSING_PKGS=()
command -v earlyoom  >/dev/null 2>&1 || MISSING_PKGS+=(earlyoom)
command -v rsyslogd  >/dev/null 2>&1 || MISSING_PKGS+=(rsyslog)
command -v sar       >/dev/null 2>&1 || MISSING_PKGS+=(sysstat)
command -v flock     >/dev/null 2>&1 || MISSING_PKGS+=(util-linux)
if [ ${#MISSING_PKGS[@]} -eq 0 ]; then
  ok "все на месте"
elif [ "$INSTALL_PACKAGES" = 0 ] || [ "$DRY_RUN" = 1 ]; then
  warn "не установлены: ${MISSING_PKGS[*]} (ставить я не буду: --no-install/--dry-run)"
else
  chg "apt-get install ${MISSING_PKGS[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${MISSING_PKGS[@]}"
  CHANGED+=("packages")
fi

# ── 2. Управляемые файлы (список — scripts/server_guards/manifest.psv) ──────
say "Управляемые файлы по манифесту"
MANIFEST="$TPL_ROOT/manifest.psv"
[ -f "$MANIFEST" ] || die "нет манифеста: $MANIFEST"
while IFS='|' read -r m_tpl m_dest m_mode m_sev || [ -n "$m_tpl" ]; do
  case "$m_tpl" in ''|'#'*) continue ;; esac
  [ -n "$m_dest" ] && [ -n "$m_mode" ] || die "$MANIFEST: битая строка «$m_tpl»"
  for k in "${!G[@]}"; do m_dest=${m_dest//@@$k@@/${G[$k]}}; done
  case "$m_dest" in *@@*) die "$MANIFEST: неподставленная @@…@@ в пути $m_dest" ;; esac
  install_file "$m_tpl" "$m_dest" "$m_mode"
done < "$MANIFEST"

# ── 3. crontab прикладного пользователя ─────────────────────────────────────
say "crontab $APP_USER (планировщик через обёртку + две сторожевые строки)"
CRON_TMP=$(mktemp)
render "$TPL_ROOT/cron/app-user.crontab" > "$CRON_TMP"
CUR_CRON=$(mktemp)
crontab -u "$APP_USER" -l > "$CUR_CRON" 2>/dev/null || : > "$CUR_CRON"
if cmp -s "$CRON_TMP" "$CUR_CRON"; then
  ok "crontab $APP_USER"
else
  [ "$SHOW_DIFF" = 1 ] && diff -u "$CUR_CRON" "$CRON_TMP" | sed 's/^/      /' || true
  if [ "$DRY_RUN" = 1 ]; then
    chg "crontab $APP_USER (dry-run)"; CHANGED+=("crontab:$APP_USER")
  else
    mkdir -p "$BACKUP_DIR"
    cp -a "$CUR_CRON" "$BACKUP_DIR/crontab-$APP_USER.txt"
    crontab -u "$APP_USER" "$CRON_TMP"
    chg "crontab $APP_USER"; CHANGED+=("crontab:$APP_USER")
  fi
fi
rm -f "$CRON_TMP" "$CUR_CRON"

# ── 3b. crontab root — авто-деплой (H1933) ──────────────────────────────────
# Отдельный крон root: deploy.sh перезагружает php-fpm и рестартует Horizon,
# прикладному пользователю это недоступно.
say "crontab root (авто-деплой каждые 30 минут через обёртку)"
CRON_TMP=$(mktemp)
render "$TPL_ROOT/cron/root.crontab" > "$CRON_TMP"
CUR_CRON=$(mktemp)
crontab -l > "$CUR_CRON" 2>/dev/null || : > "$CUR_CRON"
if cmp -s "$CRON_TMP" "$CUR_CRON"; then
  ok "crontab root"
else
  [ "$SHOW_DIFF" = 1 ] && diff -u "$CUR_CRON" "$CRON_TMP" | sed 's/^/      /' || true
  if [ "$DRY_RUN" = 1 ]; then
    chg "crontab root (dry-run)"; CHANGED+=("crontab:root")
  else
    mkdir -p "$BACKUP_DIR"
    cp -a "$CUR_CRON" "$BACKUP_DIR/crontab-root.txt"
    crontab "$CRON_TMP"
    chg "crontab root"; CHANGED+=("crontab:root")
  fi
fi
# Зеркало 644: cabinet:probe / guards:verify от www-data не читают
# /var/spool/cron/crontabs/root (600), а bare `crontab -l` врёт чужим user'ом.
if [ "$DRY_RUN" = 0 ]; then
  MIRROR_DIR="$APP_DIR/storage/app/server_guards"
  MIRROR="$MIRROR_DIR/crontab-root.installed"
  mkdir -p "$MIRROR_DIR"
  if crontab -l > "$MIRROR.tmp" 2>/dev/null; then
    if [ ! -f "$MIRROR" ] || ! cmp -s "$MIRROR.tmp" "$MIRROR"; then
      install -m 644 "$MIRROR.tmp" "$MIRROR"
      chown "root:$APP_USER" "$MIRROR" 2>/dev/null || true
      chg "mirror $MIRROR"; CHANGED+=("mirror:crontab-root")
    else
      ok "mirror $MIRROR"
    fi
  fi
  rm -f "$MIRROR.tmp"
fi
rm -f "$CRON_TMP" "$CUR_CRON"

# ── 4. Инвариант единственного определения чисел памяти ─────────────────────
say "systemd: числа памяти ровно в одном drop-in'е на юнит"
# Ловушка 29-07: числа памяти в ДВУХ drop-in'ах → эффективное значение решает
# сортировка имён, а не замысел. Своё мы держим в memory-cap.conf; чужой файл
# скрипт не удаляет — он останавливается и называет его.
for unit_dir in /etc/systemd/system/cron.service.d /etc/systemd/system/supervisor.service.d; do
  [ -d "$unit_dir" ] || continue
  offenders=()
  for f in "$unit_dir"/*.conf; do
    [ -f "$f" ] || continue
    [ "$(basename "$f")" = "memory-cap.conf" ] && continue
    grep -qE '^[[:space:]]*Memory(High|Max)[[:space:]]*=' "$f" && offenders+=("$f")
  done
  if [ ${#offenders[@]} -gt 0 ]; then
    die "числа памяти заданы не только в memory-cap.conf: ${offenders[*]} — уберите директивы Memory* оттуда (эффективное значение решает сортировка имён)"
  fi
  ok "$unit_dir :: числа памяти ровно в одном файле"
done

# ── 5. Файлы, которые нам не принадлежат целиком — правка директив на месте ──
say "PHP $PHP_VERSION: пул fpm (файл дистрибутивный, меняем только директивы)"
FPM_POOL="/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
ensure_directive "$FPM_POOL" pm.max_children "${G[FPM_MAX_CHILDREN]}"
ensure_directive "$FPM_POOL" pm.max_requests "${G[FPM_MAX_REQUESTS]}"

ensure_directive /etc/sysstat/sysstat HISTORY "${G[SYSSTAT_HISTORY_DAYS]}" "="
ensure_directive /etc/default/sysstat ENABLED '"true"' "="

# ── 7. Перезагрузка того, что изменилось ────────────────────────────────────
say "Применение изменений"
if [ ${#CHANGED[@]} -eq 0 ]; then
  ok "менять нечего — предохранители уже на месте"
else
  touched() { printf '%s\n' "${CHANGED[@]}" | grep -q "$1"; }
  if [ "$DRY_RUN" = 1 ]; then
    warn "dry-run: ${#CHANGED[@]} изменений НЕ применено"
  else
    systemctl daemon-reload
    ok "systemctl daemon-reload"

    if touched 'journald'; then systemctl restart systemd-journald && ok "systemd-journald перезапущен"; fi
    if touched 'default/earlyoom'; then
      systemctl enable --now earlyoom >/dev/null 2>&1 || true
      systemctl restart earlyoom && ok "earlyoom перезапущен"
    fi
    if touched 'memwatch'; then
      systemctl enable --now memwatch.timer >/dev/null 2>&1 && ok "memwatch.timer включён"
    fi
    if touched 'sysstat'; then
      systemctl enable --now sysstat.service >/dev/null 2>&1 || true
      systemctl restart sysstat-collect.timer >/dev/null 2>&1 && ok "sysstat-collect.timer перезапущен"
    fi
    if touched 'fpm/pool.d'; then
      systemctl reload "php$PHP_VERSION-fpm" && ok "php$PHP_VERSION-fpm перезагружен"
    fi
    # H3181 — правила старения /tmp. --create применяет их сразу; чистку делает
    # штатный systemd-tmpfiles-clean.timer, здесь её не зовём: удаление файлов
    # обязано происходить по расписанию, а не как побочный эффект применения
    # предохранителей.
    if touched 'tmpfiles.d'; then
      systemd-tmpfiles --create >/dev/null 2>&1 && ok "systemd-tmpfiles --create" \
        || warn "systemd-tmpfiles --create ругнулся: systemd-tmpfiles --create (посмотреть вывод)"
    fi
    # H3181 — потолок /tmp. Drop-in действует со следующей загрузки, а
    # `systemctl restart tmp.mount` РАЗМОНТИРОВАЛ бы живой /tmp вместе с чужими
    # открытыми файлами. remount меняет size на месте, никого не роняя.
    if touched 'tmp.mount.d'; then
      if mount -o "remount,size=${G[TMP_TMPFS_SIZE]}" /tmp 2>/dev/null; then
        ok "/tmp перемонтирован с size=${G[TMP_TMPFS_SIZE]} (drop-in вступит в силу и при следующей загрузке)"
      elif findmnt -no OPTIONS /tmp 2>/dev/null | grep -qE '(^|,)uid=[1-9]'; then
        # Замер .92 19-08-2026: tmpfs смонтирован ХОСТОМ вне пространства имён
        # контейнера (idmap LXC, uid=100000). remount изнутри падает на
        # «Invalid uid», и ни этот drop-in, ни /etc/fstab делу не помогут —
        # обещать «вступит в силу после перезагрузки» здесь было бы неправдой.
        warn "/tmp смонтирован ХОСТОМ (в опциях чужой uid=) — изнутри потолок не ставится ВООБЩЕ, ни remount'ом, ни после перезагрузки. Это сторона Proxmox: P5 плана, задача Артёма. Подробности: docs/server-resource-guards.md §10.1"
      else
        warn "/tmp не перемонтировался — потолок вступит в силу после перезагрузки. Проверить: findmnt -no OPTIONS /tmp"
      fi
    fi
    # H3181 — Restart=on-failure у nginx. Политику рестарта systemd берёт на
    # daemon-reload (он выше), сам nginx перезапускать НЕ надо: это уронило бы
    # сайт ради настройки, которая нужна именно чтобы он не лежал.
    if touched 'nginx.service.d'; then
      eff_nginx_restart=$(systemctl show nginx -p Restart --value 2>/dev/null || echo '')
      [ "$eff_nginx_restart" = "on-failure" ] \
        && ok "nginx: Restart=on-failure" \
        || warn "nginx: Restart=$eff_nginx_restart — ожидалось on-failure"
    fi
    systemctl enable --now rsyslog >/dev/null 2>&1 || true

    # cron: cgroup-свойства systemd подхватывает на daemon-reload, но если
    # эффективное значение всё ещё чужое — юнит надо перезапустить. Планировщик
    # перезапускать безопасно: обёртка на flock пропустит минуту, не более.
    eff_cron_max=$(systemctl show cron -p MemoryMax --value 2>/dev/null || echo '')
    if touched 'cron.service.d' && [ -n "$eff_cron_max" ]; then
      systemctl restart cron && ok "cron перезапущен (лимиты вступили в силу)"
    fi
    # H3121: юнит демона MadelineProto. enable --now, а не restart: если он уже
    # крутится, перезапуск убил бы живого демона на ровном месте — а изменение
    # файла юнита без смены чисел этого не требует. Числа меняются достаточно
    # редко, чтобы перезапуск делал человек осознанно.
    if touched 'systema-madeline-daemon'; then
      systemctl enable --now systema-madeline-daemon.service >/dev/null 2>&1 \
        && ok "systema-madeline-daemon включён" \
        || warn "systema-madeline-daemon не поднялся: journalctl -u systema-madeline-daemon -n 50"
      systemctl try-restart systema-madeline-daemon.service >/dev/null 2>&1 || true
    fi
    # H3410: таймер, не сервис — enable --now просто ставит следующий тик,
    # ничего не перезапускает и не может уронить текущий прогон докатки.
    if touched 'systema-yandex-resume'; then
      systemctl enable --now systema-yandex-resume.timer >/dev/null 2>&1 \
        && ok "systema-yandex-resume.timer включён" \
        || warn "systema-yandex-resume.timer не включился: systemctl status systema-yandex-resume.timer"
    fi
    if touched 'supervisor.service.d'; then
      if [ "$RESTART_SUPERVISOR" = 1 ]; then
        systemctl restart supervisor && ok "supervisor перезапущен"
      else
        warn "лимиты supervisor.service вступят в силу после перезапуска: systemctl restart supervisor (или --restart-supervisor). Это перезапустит Horizon/Reverb."
      fi
    fi
    [ -d "$BACKUP_DIR" ] && ok "бэкап изменённых файлов: $BACKUP_DIR"
  fi
fi

# ── 8. Итог + громкая проверка ──────────────────────────────────────────────
say "Проверка результата"
if [ "$DRY_RUN" = 1 ]; then
  printf '  dry-run: %d файлов разошлись с репозиторием\n' "${#CHANGED[@]}"
  exit 0
fi
if [ ! -f "$APP_DIR/artisan" ]; then
  warn "нет $APP_DIR/artisan — guards:verify не запущен"
elif ! sudo -u "$APP_USER" "${G[PHP_BIN]}" "$APP_DIR/artisan" list --raw 2>/dev/null | grep -q '^guards:verify'; then
  # Предохранители можно ставить и на машину, где код ещё не выложен (первый
  # прогон на свежем контейнере). Тогда проверять нечем — но и молчать нельзя.
  warn "команда guards:verify ещё не выложена — проверить нечем; после деплоя: php artisan guards:verify"
else
  cd "$APP_DIR"
  if sudo -u "$APP_USER" "${G[PHP_BIN]}" artisan guards:verify; then
    printf '\n%s✔ Предохранители на месте (%d изменений в этот прогон).%s\n' "$GRN" "${#CHANGED[@]}" "$OFF"
  else
    die "guards:verify недоволен — см. список выше"
  fi
fi
