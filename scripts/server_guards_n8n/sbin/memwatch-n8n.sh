#!/bin/bash
# memwatch-n8n.sh — одна строка в минуту про память .91, плюс полный дамп, когда
# памяти мало.
#
# Родственник /usr/local/sbin/memwatch.sh с .92, но НЕ его копия: там считают
# php-процессы и artisan schedule:run, здесь — контейнеры и их cgroup'ы. Общий
# у них только замысел: 29-07-2026 на .92 зависание разбирали вслепую, потому
# что никто не записал, КТО съел память. На .91 на 25-08-2026 не было даже этого.
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh --profile n8n из
# scripts/server_guards_n8n/sbin/memwatch-n8n.sh. Править копию в репозитории,
# а не на сервере.
LOG=/var/log/memwatch.log
DUMP=/var/log/memwatch-pressure.log
THRESHOLD_PCT=${MEMWATCH_THRESHOLD_PCT:-@@MEMWATCH_THRESHOLD_PCT@@}

MEMTOTAL=$(awk '/^MemTotal:/ {print $2; exit}' /proc/meminfo)
MEMAVAIL=$(awk '/^MemAvailable:/ {print $2; exit}' /proc/meminfo)
SHMEM=$(awk '/^Shmem:/ {print $2; exit}' /proc/meminfo)
SWTOTAL=$(awk '/^SwapTotal:/ {print $2; exit}' /proc/meminfo)
SWFREE=$(awk '/^SwapFree:/ {print $2; exit}' /proc/meminfo)
PCT=$(( MEMAVAIL * 100 / MEMTOTAL ))
SWUSED=$(( (SWTOTAL - SWFREE) / 1024 ))
LOAD=$(cut -d' ' -f1-3 /proc/loadavg)
NPROC=$(ls -d /proc/[0-9]* 2>/dev/null | wc -l)
# /tmp здесь — tmpfs, смонтированный ХОСТОМ, и каждый байт в нём это заявка на
# RAM без потолка (см. server_guards_n8n.conf, секция /tmp). Держать его в
# ежеминутной строке — единственный способ увидеть, как он растёт.
TMPMB=$(df -m --output=used /tmp 2>/dev/null | tail -1 | tr -d ' ')
TS=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

# Память контейнеров берётся из cgroup, а не из `docker stats`: docker stats
# стоит секунду на выборку и на минутном такте это заметно, а cgroup читается
# мгновенно и даёт ту же цифру.
cg_mem() { # cg_mem <container-name> -> МиБ или "-"
  local id p
  id=$(docker inspect -f '{{.Id}}' "$1" 2>/dev/null) || { printf '%s' '-'; return; }
  [ -n "$id" ] || { printf '%s' '-'; return; }
  p="/sys/fs/cgroup/system.slice/docker-$id.scope/memory.current"
  [ -r "$p" ] && printf '%s' "$(( $(cat "$p") / 1048576 ))" || printf '%s' '-'
}
N8N_MB=$(cg_mem '@@N8N_CONTAINER@@')
CADDY_MB=$(cg_mem '@@CADDY_CONTAINER@@')

printf '%s avail=%sMB/%sMB (%s%%) swap_used=%sMB shmem=%sMB tmp=%sMB load=%s procs=%s n8n=%sMB caddy=%sMB\n' \
  "$TS" "$((MEMAVAIL/1024))" "$((MEMTOTAL/1024))" "$PCT" "$SWUSED" "$((SHMEM/1024))" \
  "${TMPMB:--}" "$LOAD" "$NPROC" "$N8N_MB" "$CADDY_MB" >> "$LOG"

if [ "$PCT" -lt "$THRESHOLD_PCT" ]; then
  {
    echo "===== $TS  MEMORY PRESSURE: ${PCT}% available, swap_used=${SWUSED}MB, load=$LOAD ====="
    ps -eo pid,ppid,user,rss,etimes,stat,comm,args --sort=-rss --no-headers 2>/dev/null | head -25 | cut -c1-220
    echo "--- rss summed by command ---"
    ps -eo rss,comm --no-headers 2>/dev/null \
      | awk '{s[$2]+=$1; c[$2]++} END {for (k in s) printf "%9.1f MB %4d %s\n", s[k]/1024, c[k], k}' \
      | sort -rn | head -12
    # Крупные файлы в /tmp — не процессы, и ps их не покажет; на этой машине
    # именно они дважды оказывались причиной, а не следствием.
    echo "--- largest /tmp entries ---"
    du -sh /tmp/* 2>/dev/null | sort -rh | head -10
    echo "--- containers ---"
    docker ps --format '{{.Names}}\t{{.Status}}' 2>/dev/null
    echo
  } >> "$DUMP"
fi
