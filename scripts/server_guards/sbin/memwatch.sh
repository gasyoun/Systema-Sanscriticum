#!/bin/bash
# memwatch.sh — one line per minute, plus a full process dump when memory is tight.
# Written after the 29-07-2026 hang, where nothing recorded WHICH processes ate the RAM.
#
# MANAGED FILE — installed by scripts/server_guards_apply.sh from
# scripts/server_guards/sbin/memwatch.sh. Edit the repo copy, not the server copy.
LOG=/var/log/memwatch.log
DUMP=/var/log/memwatch-pressure.log
THRESHOLD_PCT=${MEMWATCH_THRESHOLD_PCT:-@@MEMWATCH_THRESHOLD_PCT@@}

MEMTOTAL=$(awk '/^MemTotal:/ {print $2; exit}' /proc/meminfo)
MEMAVAIL=$(awk '/^MemAvailable:/ {print $2; exit}' /proc/meminfo)
PCT=$(( MEMAVAIL * 100 / MEMTOTAL ))
LOAD=$(cut -d' ' -f1-3 /proc/loadavg)
NPROC=$(ls -d /proc/[0-9]* 2>/dev/null | wc -l)
NPHP=$(pgrep -c 'php' 2>/dev/null); NPHP=${NPHP:-0}; [ -z "$NPHP" ] && NPHP=0
NSCHED=$(pgrep -f 'artisan schedule:run' 2>/dev/null | wc -l)
TS=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

printf '%s avail=%sMB/%sMB (%s%%) load=%s procs=%s php=%s schedule_run=%s\n' \
  "$TS" "$((MEMAVAIL/1024))" "$((MEMTOTAL/1024))" "$PCT" "$LOAD" "$NPROC" "$NPHP" "$NSCHED" >> "$LOG"

if [ "$PCT" -lt "$THRESHOLD_PCT" ]; then
  {
    echo "===== $TS  MEMORY PRESSURE: ${PCT}% available, load=$LOAD, procs=$NPROC ====="
    ps -eo pid,ppid,user,rss,etimes,stat,comm,args --sort=-rss --no-headers 2>/dev/null | head -25 | cut -c1-220
    echo "--- rss summed by command ---"
    ps -eo rss,comm --no-headers 2>/dev/null \
      | awk '{s[$2]+=$1; c[$2]++} END {for (k in s) printf "%9.1f MB %4d %s\n", s[k]/1024, c[k], k}' \
      | sort -rn | head -12
    echo
  } >> "$DUMP"
fi
