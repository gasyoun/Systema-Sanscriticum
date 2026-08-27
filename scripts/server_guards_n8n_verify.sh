#!/usr/bin/env bash
# server_guards_n8n_verify.sh — громкая проверка предохранителей машины .91.
#
# Роль та же, что у `php artisan guards:verify` на .92: сверить ЖИВУЮ машину с
# числами из scripts/server_guards_n8n.conf и с манифестом. Отдельный скрипт, а
# не команда artisan, ровно потому, что на .91 нет Laravel и не будет.
#
# Использование (на .91, от root):
#   bash scripts/server_guards_n8n_verify.sh              # человекочитаемо
#   bash scripts/server_guards_n8n_verify.sh --json PATH  # плюс машинный статус
#   bash scripts/server_guards_n8n_verify.sh --publish     # статус в /srv/restic/status
#
# КАК ЭТО ЧИТАЮТ С .92 (W2d — «одна команда отвечает, обе ли машины под
# предохранителями»). Прямой SSH между машинами по публичным адресам закрыт, а
# существующий ключ restic-push намеренно ограничен: `restrict,
# command="internal-sftp"` — выполнить команду по нему НЕЛЬЗЯ. Заводить ради
# проверки второе ребро доверия между двумя продовыми машинами — плохой размен.
# Поэтому .91 САМА кладёт статус в /srv/restic/status/n8n-guards.json, а
# /srv/restic — это в точности ChrootDirectory пользователя restic-push, то есть
# файл виден с .92 как /status/n8n-guards.json по УЖЕ выданному ключу:
#
#   ssh .92: sftp -i /root/.ssh/id_restic_push restic-push@192.168.200.91:/status/n8n-guards.json /tmp/
#
# Свежесть самого файла — часть проверки: протухший статус означает, что
# проверка на .91 перестала ходить, и это тревога, а не тишина.
#
# Коды выхода: 0 всё на месте · 1 есть critical · 2 только warning
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF_FILE="${SERVER_GUARDS_CONF:-$SCRIPT_DIR/server_guards_n8n.conf}"
TPL_ROOT="$SCRIPT_DIR/server_guards_n8n"
JSON_OUT=""
PUBLISH_DIR=/srv/restic/status

while [ $# -gt 0 ]; do
  case "$1" in
    --json) shift; JSON_OUT="${1:-}" ;;
    --publish) JSON_OUT="$PUBLISH_DIR/n8n-guards.json" ;;
    -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Неизвестный аргумент: $1" >&2; exit 2 ;;
  esac
  shift
done

[ -f "$CONF_FILE" ] || { echo "нет файла значений: $CONF_FILE" >&2; exit 1; }

BOLD=$(printf '\033[1m'); RED=$(printf '\033[1;31m'); GRN=$(printf '\033[1;32m')
YLW=$(printf '\033[1;33m'); CYA=$(printf '\033[1;36m'); OFF=$(printf '\033[0m')
CRIT=0; WARN=0; OKN=0
FINDINGS=()
say()  { printf '\n%s▶ %s%s\n' "$CYA" "$*" "$OFF"; }
ok()   { OKN=$((OKN+1)); printf '  %sok%s       %s\n' "$GRN" "$OFF" "$*"; }
crit() { CRIT=$((CRIT+1)); FINDINGS+=("critical|$1|$2"); printf '  %scritical%s %s — %s\n' "$RED" "$OFF" "$1" "$2"; }
warn() { WARN=$((WARN+1)); FINDINGS+=("warning|$1|$2"); printf '  %swarning%s  %s — %s\n' "$YLW" "$OFF" "$1" "$2"; }

# ── Значения ────────────────────────────────────────────────────────────────
declare -A G=()
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in ''|'#'*|[[:space:]]*) continue ;; esac
  case "$line" in *=*) ;; *) continue ;; esac
  key=${line%%=*}; val=${line#*=}; val=${val%$'\r'}
  case "$val" in '"'*'"') val=${val#\"}; val=${val%\"} ;; esac
  G[$key]="$val"
done < "$CONF_FILE"

render() { local body; body=$(cat "$1"); local k
  for k in "${!G[@]}"; do body=${body//@@$k@@/${G[$k]}}; done
  printf '%s\n' "$body"; }

# ── 1. Манифест: наличие И расхождение ──────────────────────────────────────
# Проверять только наличие — значит пропустить файл, который кто-то поправил на
# машине. Именно ради этого манифест ОДИН на applier и verify.
say "Управляемые файлы по манифесту"
MANIFEST="$TPL_ROOT/manifest.psv"
if [ ! -f "$MANIFEST" ]; then
  crit "manifest" "нет $MANIFEST"
else
  while IFS='|' read -r m_tpl m_dest m_mode m_sev || [ -n "$m_tpl" ]; do
    case "$m_tpl" in ''|'#'*) continue ;; esac
    [ -n "$m_dest" ] || continue
    for k in "${!G[@]}"; do m_dest=${m_dest//@@$k@@/${G[$k]}}; done
    if [ ! -f "$m_dest" ]; then
      [ "$m_sev" = critical ] && crit "$m_dest" "файла нет" || warn "$m_dest" "файла нет"
      continue
    fi
    tmp=$(mktemp); render "$TPL_ROOT/$m_tpl" > "$tmp"
    if cmp -s "$tmp" "$m_dest"; then
      cur_mode=$(stat -c '%a' "$m_dest")
      [ "$cur_mode" = "$m_mode" ] && ok "$m_dest" || warn "$m_dest" "права $cur_mode, ожидались $m_mode"
    else
      [ "$m_sev" = critical ] && crit "$m_dest" "содержимое разошлось с репозиторием" \
                              || warn "$m_dest" "содержимое разошлось с репозиторием"
    fi
    rm -f "$tmp"
  done < "$MANIFEST"
fi

# ── 2. Потолки контейнеров ──────────────────────────────────────────────────
# Ноль здесь — это не «по умолчанию», а «предохранителя нет»: контейнер вправе
# забрать всю машину. Замер 25-08-2026 застал ровно ноль на обоих.
say "Потолки и здоровье контейнеров"
to_bytes() { local n="${1%[GgMmKk]}" u="${1: -1}"
  case "$u" in G|g) echo $((n*1073741824));; M|m) echo $((n*1048576));; K|k) echo $((n*1024));; *) echo "$1";; esac; }
check_container() { # <name> <mem> <pids> <expect_health>
  local c="$1" want_mem want_pids cur_mem cur_pids health
  if ! docker inspect "$c" >/dev/null 2>&1; then crit "$c" "контейнера нет"; return; fi
  want_mem=$(to_bytes "$2"); want_pids="$3"
  cur_mem=$(docker inspect -f '{{.HostConfig.Memory}}' "$c")
  cur_pids=$(docker inspect -f '{{if .HostConfig.PidsLimit}}{{.HostConfig.PidsLimit}}{{else}}0{{end}}' "$c")
  [ "$cur_mem" = "$want_mem" ] && ok "$c memory=$2" || crit "$c" "memory=$cur_mem, ожидалось $want_mem ($2)"
  [ "$cur_pids" = "$want_pids" ] && ok "$c pids=$want_pids" || crit "$c" "pids=$cur_pids, ожидалось $want_pids"
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c")
  case "$health" in
    healthy) ok "$c health=healthy" ;;
    none)    warn "$c" "healthcheck отсутствует (health=none) — вступит в силу при следующем законном пересоздании контейнера" ;;
    *)       crit "$c" "health=$health" ;;
  esac
}
check_container "${G[N8N_CONTAINER]}"   "${G[N8N_MEMORY_LIMIT]}"   "${G[N8N_PIDS_LIMIT]}"
check_container "${G[CADDY_CONTAINER]}" "${G[CADDY_MEMORY_LIMIT]}" "${G[CADDY_PIDS_LIMIT]}"

# Пин образа по digest. `:latest` — это «неизвестно что будет завтра»: ровно так
# 01-08-2026 закрывали C07 для n8n (H1961), а caddy остался незакрытым.
for c in "${G[N8N_CONTAINER]}" "${G[CADDY_CONTAINER]}"; do
  img=$(docker inspect -f '{{.Config.Image}}' "$c" 2>/dev/null || echo '')
  case "$img" in
    *@sha256:*) ok "$c образ закреплён digest'ом" ;;
    '')         : ;;
    # Пин живёт в docker-compose.override.yml (его целостность проверена выше
    # как critical), но у РАБОТАЮЩЕГО контейнера образ меняется только при
    # пересоздании — а пересоздание здесь законный шаг человека, не агента.
    # Поэтому warning с указанием, чего именно ждём, а не critical: кричать
    # critical'ом о том, что сам же и запретил себе чинить, — это не проверка,
    # а шум.
    *)          warn "$c" "образ $img ещё не закреплён digest'ом на живом контейнере; пин подготовлен в ${G[N8N_DIR]}/docker-compose.override.yml и вступит в силу при следующем законном пересоздании" ;;
  esac
done

# ── 3. earlyoom и юниты ─────────────────────────────────────────────────────
say "Юниты и таймеры"
for u in ${G[REQUIRED_ACTIVE_UNITS]//,/ }; do
  systemctl is-active --quiet "$u" && ok "юнит $u активен" || crit "$u" "юнит не активен"
done
for t in ${G[REQUIRED_ACTIVE_TIMERS]//,/ }; do
  systemctl is-active --quiet "$t" && ok "таймер $t активен" || warn "$t" "таймер не активен"
done

# ── 4. Свежесть и правдоподобие резервной копии ─────────────────────────────
# Возраст без размера — зелёная лампочка над пустым сейфом (два обрезка по
# 11.7 МиБ на yandex_disk у .92 назывались здоровым бэкапом, H3181).
say "Резервные копии n8n"
newest=$(ls -1t "${G[N8N_BACKUP_DIR]}"/n8n-*.tar.gz 2>/dev/null | head -1 || true)
if [ -z "$newest" ]; then
  crit "backup" "в ${G[N8N_BACKUP_DIR]} нет ни одного архива"
else
  age_h=$(( ( $(date +%s) - $(stat -c '%Y' "$newest") ) / 3600 ))
  size_mb=$(( $(stat -c '%s' "$newest") / 1048576 ))
  [ "$age_h" -le "${G[N8N_BACKUP_MAX_AGE_HOURS]}" ] \
    && ok "новейший архив $age_h ч назад" \
    || crit "backup" "новейший архив $age_h ч назад, порог ${G[N8N_BACKUP_MAX_AGE_HOURS]} ч"
  [ "$size_mb" -ge "${G[N8N_BACKUP_MIN_MB]}" ] \
    && ok "новейший архив $size_mb МиБ" \
    || crit "backup" "новейший архив $size_mb МиБ — меньше пола ${G[N8N_BACKUP_MIN_MB]} МиБ, похоже на обрезок"
fi
if [ -n "${G[N8N_BACKUP_OFFSITE_REPO]:-}" ]; then
  # Задано — этого мало. «Назначение прописано» и «копия туда доехала» — разные
  # утверждения, и зелёная лампочка над первым из них ровно та ошибка, которую
  # уже поймали на yandex_disk у .92 (H3181). Спрашиваем сам репозиторий.
  pass="${G[N8N_BACKUP_OFFSITE_PASSFILE]:-}"
  if [ ! -s "$pass" ]; then
    crit "backup-offsite" "нет файла пароля ${pass:-<пусто>} — до репозитория не достучаться"
  else
    last=$(restic -r "${G[N8N_BACKUP_OFFSITE_REPO]}" --password-file "$pass" \
             snapshots --tag n8n --latest 1 --json 2>/dev/null \
           | grep -o '"time":"[^"]*"' | head -1 | cut -d'"' -f4)
    if [ -z "$last" ]; then
      crit "backup-offsite" "репозиторий ${G[N8N_BACKUP_OFFSITE_REPO]} не отвечает или в нём нет ни одного снимка n8n"
    else
      last_s=$(date -d "$last" +%s 2>/dev/null || echo 0)
      age_h=$(( ( $(date +%s) - last_s ) / 3600 ))
      [ "$age_h" -le "${G[N8N_BACKUP_MAX_AGE_HOURS]}" ] \
        && ok "off-site снимок $age_h ч назад (${G[N8N_BACKUP_OFFSITE_REPO]})" \
        || crit "backup-offsite" "новейший off-site снимок $age_h ч назад, порог ${G[N8N_BACKUP_MAX_AGE_HOURS]} ч"
    fi
  fi
else
  crit "backup-offsite" "off-site назначение НЕ задано: копия локальная и делит судьбу машины. Что делать — см. секцию off-site в server_guards_n8n.conf"
fi

# ── 5. Память и /tmp ────────────────────────────────────────────────────────
say "Память"
swap_total=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)
swap_free=$(awk '/^SwapFree:/ {print $2}' /proc/meminfo)
if [ "$swap_total" -gt 0 ]; then
  swap_used_pct=$(( (swap_total - swap_free) * 100 / swap_total ))
  [ "$swap_used_pct" -lt 90 ] \
    && ok "своп занят на ${swap_used_pct}%" \
    || crit "swap" "своп занят на ${swap_used_pct}% — это предпосылка livelock'а 24-07/28-07"
fi
# ЧЕСТНО: потолок tmpfs изнутри гостя не ставится (см. conf). Проверяем то, что
# проверить МОЖНО — что /tmp не разросся, — и не делаем вид, что есть потолок.
tmp_mb=$(df -m --output=used /tmp 2>/dev/null | tail -1 | tr -d ' ')
if findmnt -no OPTIONS /tmp 2>/dev/null | grep -qE '(^|,)uid=[1-9]'; then
  [ "${tmp_mb:-0}" -lt 4096 ] \
    && ok "/tmp занят ${tmp_mb} МиБ (потолок ставится только со стороны хоста — P5, Артём)" \
    || warn "tmpfs" "/tmp занят ${tmp_mb} МиБ, а потолок изнутри гостя не ставится (P5, сторона хоста)"
fi

# ── 6. Итог ─────────────────────────────────────────────────────────────────
printf '\n'
if [ "$CRIT" -gt 0 ]; then
  printf '%s✖ .91: %d critical, %d warning, %d ok%s\n' "$RED" "$CRIT" "$WARN" "$OKN" "$OFF"; STATUS=critical; RC=1
elif [ "$WARN" -gt 0 ]; then
  printf '%s⚠ .91: %d warning, %d ok%s\n' "$YLW" "$WARN" "$OKN" "$OFF"; STATUS=warning; RC=2
else
  printf '%s✔ .91: предохранители на месте (%d проверок)%s\n' "$GRN" "$OKN" "$OFF"; STATUS=ok; RC=0
fi

if [ -n "$JSON_OUT" ]; then
  mkdir -p "$(dirname "$JSON_OUT")"
  { printf '{"host":"%s","checked_at":"%s","status":"%s","critical":%d,"warning":%d,"ok":%d,"findings":[' \
      "$(hostname)" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$STATUS" "$CRIT" "$WARN" "$OKN"
    sep=''
    for f in ${FINDINGS[@]+"${FINDINGS[@]}"}; do
      sev=${f%%|*}; rest=${f#*|}; what=${rest%%|*}; why=${rest#*|}
      printf '%s{"severity":"%s","subject":"%s","detail":"%s"}' "$sep" "$sev" "${what//\"/\'}" "${why//\"/\'}"
      sep=','
    done
    printf ']}\n'
  } > "$JSON_OUT"
  chmod 644 "$JSON_OUT"
  printf '  статус записан: %s\n' "$JSON_OUT"
fi
exit $RC
