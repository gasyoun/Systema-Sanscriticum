#!/usr/bin/env bash
# 00-preflight.sh — проверка источника данных (.91) и готовности нового бокса.
# Запуск: на НОВОМ боксе (проверяет и себя, и .91 по SSH-ключу root).
# Режимы: --source-only = только проверка restic-repo на .91.
set -euo pipefail

SOURCE_HOST="${SOURCE_HOST:-193.232.229.91}"
REPO_DIR="${REPO_DIR:-/srv/restic/systema}"
MIN_SNAPSHOTS="${MIN_SNAPSHOTS:-70}"
MIN_FREE_GB_NEW="${MIN_FREE_GB_NEW:-60}"

say() { printf '[preflight] %s\n' "$*"; }
fail() { printf '[preflight][FAIL] %s\n' "$*" >&2; exit 1; }

# --- Новый бокс: самопроверка ---
if [ "${1:-}" != "--source-only" ]; then
    say "OS: $(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")"
    [ "$(id -u)" -eq 0 ] || fail "запускать под root"
    free_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
    [ "$free_mb" -ge 15000 ] || fail "RAM ${free_mb}MB < 15GB — хостер дал не тот тариф"
    disk_gb=$(df --output=avail -BG / | tail -1 | tr -dc '0-9')
    [ "$disk_gb" -ge "$MIN_FREE_GB_NEW" ] || fail "на / свободно ${disk_gb}G < ${MIN_FREE_GB_NEW}G"
    say "RAM ${free_mb}MB, диск /: ${disk_gb}G свободно — OK"
fi

# --- Источник: .91 и репозиторий ---
say "проверяю ${SOURCE_HOST}..."
ssh -o ConnectTimeout=10 -o BatchMode=yes "root@${SOURCE_HOST}" "test -d '${REPO_DIR}/snapshots'" \
    || fail "репозиторий ${REPO_DIR} на ${SOURCE_HOST} не найден (ключ/путь?)"

snap_count=$(ssh -o BatchMode=yes "root@${SOURCE_HOST}" "ls '${REPO_DIR}/snapshots' | wc -l")
[ "$snap_count" -ge "$MIN_SNAPSHOTS" ] || fail "снапшотов ${snap_count} < ${MIN_SNAPSHOTS} — repo подозрителен"
newest=$(ssh -o BatchMode=yes "root@${SOURCE_HOST}" \
    "ls -t '${REPO_DIR}/snapshots' | head -1 | xargs -I{} stat -c '%y' '${REPO_DIR}/snapshots/{}'")
say "repo OK: снапшотов ${snap_count}, свежайший: ${newest}"

repo_gb=$(ssh -o BatchMode=yes "root@${SOURCE_HOST}" "du -sg '${REPO_DIR}' | cut -f1")
say "размер repo: ~${repo_gb} GB (scp займёт столько же на новом боксе)"

if [ "${1:-}" != "--source-only" ]; then
    command -v curl >/dev/null || fail "curl не установлен (фаза 01)"
    getent hosts samskrte.ru | grep -q 193.232.229.92 \
        && say "DNS samskrte.ru всё ещё указывает на старый IP — ожидаемо до GATE" \
        || say "ВНИМАНИЕ: DNS samskrte.ru уже НЕ старый IP — уточни у MG, не переключали ли"
fi

say "PREFLIGHT PASS"
