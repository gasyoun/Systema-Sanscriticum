#!/bin/bash
# n8n-container-limits.sh — навесить потолки памяти и pids на живые контейнеры
# .91 БЕЗ пересоздания.
#
# Почему через `docker update`, а не через docker-compose.yml. Ограда волны 2
# (PLAN §4, D19) запрещает `docker compose up`-пересоздание без человека, а
# healthcheck и потолки, записанные в compose, вступают в силу ТОЛЬКО при
# пересоздании контейнера. `docker update` меняет cgroup-лимиты у работающего
# контейнера немедленно и ничего не роняет — n8n не перезапускается, исполнения
# не теряются. Поэтому: числа применяются этим скриптом СЕЙЧАС, а в compose они
# лежат ради следующего законного пересоздания (иначе первый же `compose up`
# молча снял бы их обратно).
#
# Идемпотентен: второй прогон подряд ничего не меняет и печатает только «ok».
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh --profile n8n из
# scripts/server_guards_n8n/sbin/n8n-container-limits.sh.
set -euo pipefail

N8N='@@N8N_CONTAINER@@'
CADDY='@@CADDY_CONTAINER@@'

to_bytes() { # to_bytes 3G -> 3221225472
  local v="$1" n="${1%[GgMmKk]}" u="${1: -1}"
  case "$u" in
    G|g) echo $(( n * 1073741824 )) ;;
    M|m) echo $(( n * 1048576 )) ;;
    K|k) echo $(( n * 1024 )) ;;
    *)   echo "$v" ;;
  esac
}

apply() { # apply <container> <mem> <memres> <pids>
  local c="$1" mem="$2" memres="$3" pids="$4" cur_mem cur_pids want_mem
  if ! docker inspect "$c" >/dev/null 2>&1; then
    echo "  warn    нет контейнера $c — пропускаю"
    return 0
  fi
  want_mem=$(to_bytes "$mem")
  cur_mem=$(docker inspect -f '{{.HostConfig.Memory}}' "$c")
  cur_pids=$(docker inspect -f '{{if .HostConfig.PidsLimit}}{{.HostConfig.PidsLimit}}{{else}}0{{end}}' "$c")
  if [ "$cur_mem" = "$want_mem" ] && [ "$cur_pids" = "$pids" ]; then
    echo "  ok      $c mem=$mem pids=$pids"
    return 0
  fi
  # --memory-swap ОБЯЗАТЕЛЕН вместе с --memory: без него демон отвечает
  # «Memory limit should be smaller than already set memoryswap limit» и не
  # меняет ничего (замер .91 25-08-2026).
  #
  # Значение выбрано РАВНЫМ --memory, и это не формальность. Равенство означает
  # «контейнеру своп не положен вовсе»: разогнавшийся процесс будет убит внутри
  # своей cgroup, а не утащит машину в свопинг. Именно свопинг, а не сама
  # нехватка памяти, дал класс аварий 24-07 и 28-07 на .92 — машина оставалась
  # формально живой, но переставала отвечать. Дать контейнеру своп сверх лимита
  # значило бы воспроизвести ту же болезнь под другим именем.
  docker update --memory "$mem" --memory-swap "$mem" --memory-reservation "$memres" \
                --pids-limit "$pids" "$c" >/dev/null
  echo "  changed $c mem=$cur_mem->$mem (swap=$mem) pids=$cur_pids->$pids"
}

echo "▶ Потолки контейнеров (docker update — без пересоздания)"
apply "$N8N"   '@@N8N_MEMORY_LIMIT@@'   '@@N8N_MEMORY_RESERVATION@@'   '@@N8N_PIDS_LIMIT@@'
apply "$CADDY" '@@CADDY_MEMORY_LIMIT@@' '@@CADDY_MEMORY_RESERVATION@@' '@@CADDY_PIDS_LIMIT@@'
