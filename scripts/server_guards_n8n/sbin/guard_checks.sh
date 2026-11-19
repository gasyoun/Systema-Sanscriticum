#!/usr/bin/env bash
# guard_checks.sh — чистые решающие функции для H7059 на .91.
#
# Вынесены из server_guards_n8n_verify.sh, чтобы их можно было проверить
# синтетическими значениями без Docker, сети и openssl: deliberate-fail
# selftest обязан доказывать, что проверка ОТКАЗЫВАЕТ на плохом входе.
# Формат вывода: severity|subject|detail (по строке на находку); пусто = ok.

# guard_cgroup_budget <unit> <current_bytes> <max_bytes> <warn_pct>
guard_cgroup_budget() {
  local unit="$1" current="$2" max="$3" warn_pct="${4:-90}"
  case "$current" in ''|*[!0-9]*) return 0 ;; esac
  case "$max" in ''|*[!0-9]*|0) return 0 ;; esac
  case "$warn_pct" in ''|*[!0-9]*) warn_pct=90 ;; esac

  local cur_mib=$((current / 1048576))
  local max_mib=$((max / 1048576))
  if [ "$current" -ge "$max" ]; then
    printf 'critical|cgroup-budget|%s занял %d МиБ при memory.max %d МиБ — ядро убивает процессы в группе (OOM внутри cgroup), а не «сайт медленный»\n' \
      "$unit" "$cur_mib" "$max_mib"
    return 0
  fi
  if [ "$((current * 100))" -ge "$((max * warn_pct))" ]; then
    printf 'warning|cgroup-budget|%s занял %d из %d МиБ (≥%d%% memory.max) — до OOM-потолка близко\n' \
      "$unit" "$cur_mib" "$max_mib" "$warn_pct"
  fi
}

# guard_cert_expiry <host:port> <days_left> <min_days>
# days_left не число (не прочитался) — warning, НЕ молчание.
guard_cert_expiry() {
  local target="$1" days="$2" min_days="$3"
  case "$min_days" in ''|*[!0-9]*) return 0 ;; esac
  if ! [[ "$days" =~ ^-?[0-9]+$ ]]; then
    printf 'warning|cert-expiry|сертификат %s не прочитался — дни до конца неизвестны (это не «всё хорошо»)\n' "$target"
    return 0
  fi
  if [ "$days" -lt "$min_days" ]; then
    printf 'critical|cert-expiry|сертификату %s осталось %s дн (порог %s) — продление не сделано\n' \
      "$target" "$days" "$min_days"
  fi
}

# guard_backup_age <label> <age_hours> <max_age_hours>
# Возраст считается ПО НАЗНАЧЕНИЮ (локальный архив или снимок в off-site
# репозитории); нечитаемый возраст — находка, а не молчание.
guard_backup_age() {
  local label="$1" age_h="$2" max_h="$3"
  case "$max_h" in ''|*[!0-9]*) return 0 ;; esac
  case "$age_h" in ''|*[!0-9]*)
    printf 'critical|backup-fresh|%s: возраст не прочитался — это не «всё хорошо»\n' "$label"
    return 0 ;;
  esac
  if [ "$age_h" -gt "$max_h" ]; then
    printf 'critical|backup-fresh|%s: %s ч назад, порог %s ч\n' "$label" "$age_h" "$max_h"
  fi
}
