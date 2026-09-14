#!/usr/bin/env bash
# 02-fetch-restore.sh — тянет restic-репозиторий с .91 и разворачивает последний
# снапшот в /srv/migrate/restore. Данные НЕ трогаем в живых путях — всё в staging.
# Требует: RESTIC_PASSWORD (или файл /root/.restic-pass), SSH root-ключ на .91.
set -euo pipefail

SOURCE_HOST="${SOURCE_HOST:-193.232.229.91}"
REPO_SRC="${REPO_SRC:-/srv/restic/systema}"
STAGE="${STAGE:-/srv/migrate}"
RESTORE_DIR="${STAGE}/restore"
RESTIC_PASS_FILE="${RESTIC_PASS_FILE:-/root/.restic-pass}"

say() { printf '[fetch-restore] %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }

if [ ! -s "$RESTIC_PASS_FILE" ]; then
    echo "нет ${RESTIC_PASS_FILE} — положи пароль репозитория одной строкой (chmod 600)" >&2
    exit 1
fi
chmod 600 "$RESTIC_PASS_FILE"
export RESTIC_PASSWORD_FILE="$RESTIC_PASS_FILE"

say "1/4 rsync repo с ${SOURCE_HOST}:${REPO_SRC} → ${STAGE}/repo (~19 GB, терпение)"
install -d "$STAGE/repo"
rsync -a --delete "root@${SOURCE_HOST}:${REPO_SRC}/" "${STAGE}/repo/"

export RESTIC_REPOSITORY="${STAGE}/repo"
say "2/4 целостность repo + выбор последнего снапшота"
restic check --read-data-subset=5% 2>/dev/null || say "ВНИМАНИЕ: check 5% не прошёл — смотри вывод выше"
snap_id=$(restic snapshots --latest 1 --json | jq -r '.[0].id')
[ -n "$snap_id" ] && [ "$snap_id" != "null" ] || { echo "снапшоты не читаются" >&2; exit 1; }
snap_time=$(restic snapshots --json | jq -r --arg id "$snap_id" '.[] | select(.id==$id) | .time')
say "восстанавливаю снапшот ${snap_id} от ${snap_time}"

say "3/4 restore → ${RESTORE_DIR}"
rm -rf "$RESTORE_DIR"
install -d "$RESTORE_DIR"
restic restore "$snap_id" --target "$RESTORE_DIR"

say "4/4 раскладка ключевых артефактов в staging-карту"
R="${RESTORE_DIR}"
for base in "$R/var/www/html" "$R"; do
    [ -f "${base}/.env" ] && ENV_SRC="${base}/.env" && break
done
[ -n "${ENV_SRC:-}" ] || { echo ".env не найден в снапшоте" >&2; exit 1; }

grep -q '^APP_KEY=base64' "$ENV_SRC" || { echo "APP_KEY пуст — СТОП, это не тот .env" >&2; exit 1; }
install -m 640 "$ENV_SRC" "${STAGE}/env.restored"

find "$R" -type d -name app -path '*storage*' | head -1 > "${STAGE}/paths.storage_app"
NGX=$(find "$R/etc/nginx" -maxdepth 0 2>/dev/null || true)
[ -n "$NGX" ] && echo "$NGX" > "${STAGE}/paths.nginx"
DUMP=$(find "$R/var/backups/systema/db" -type f \( -name '*.sql.gz' -o -name '*.sql' \) 2>/dev/null \
       | xargs -r ls -t 2>/dev/null | head -1)
[ -n "$DUMP" ] && echo "$DUMP" > "${STAGE}/paths.dump" && say "свежий дамп: $DUMP"

SAMUDRA_DB=$(find "$R/opt/samudra/db" -type f -name '*.sqlite*' 2>/dev/null | head -1)
[ -n "${SAMUDRA_DB}" ] && echo "$SAMUDRA_DB" > "${STAGE}/paths.samudra_db" || true

say "RESTORE PASS — карта путей в ${STAGE}/paths.* ; дальше: 03-mariadb-import.sh"
