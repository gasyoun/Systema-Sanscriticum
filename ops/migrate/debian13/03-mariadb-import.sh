#!/usr/bin/env bash
# 03-mariadb-import.sh — БД и юзер из восстановленного .env + импорт дампа laravel.
# Идемпотентен: CREATE IF NOT EXISTS; повторный импорт = перезаливка данных.
set -euo pipefail

STAGE="${STAGE:-/srv/migrate}"
ENV_FILE="${STAGE}/env.restored"

say() { printf '[mariadb] %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }
[ -f "$ENV_FILE" ] || { echo "нет ${ENV_FILE} — сначала 02-fetch-restore.sh" >&2; exit 1; }

envv() { sed -n "s/^$1=//p" "$ENV_FILE" | head -1 | tr -d '"'; }
DB_DATABASE=$(envv DB_DATABASE); DB_USERNAME=$(envv DB_USERNAME); DB_PASSWORD=$(envv DB_PASSWORD)
: "${DB_DATABASE:=$(envv DB_DATABASE)}"; [ -n "$DB_DATABASE" ] || { echo "DB_* не найдены в .env" >&2; exit 1; }

DUMP_FILE=""
[ -f "${STAGE}/paths.dump" ] && DUMP_FILE=$(cat "${STAGE}/paths.dump")
[ -n "$DUMP_FILE" ] && [ -f "$DUMP_FILE" ] || { echo "дамп не найден (paths.dump)" >&2; exit 1; }
say "дамп: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

say "создаю БД `${DB_DATABASE}` и юзера `${DB_USERNAME}`"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
FLUSH PRIVILEGES;
SQL

say "импорт дампа (single-transaction-дамп, форвард-совместим в MariaDB трикси)"
case "$DUMP_FILE" in
    *.gz) gunzip -c "$DUMP_FILE" | mysql --force "${DB_DATABASE}" ;;
    *)    mysql --force "${DB_DATABASE}" < "$DUMP_FILE" ;;
esac

tables=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}';")
users_n=$(mysql -N "${DB_DATABASE}" -e 'SELECT COUNT(*) FROM users;' 2>/dev/null || echo '?')
say "импорт завершён: таблиц ${tables}, users ${users_n}"
[ "$tables" -gt 50 ] || say "ВНИМАНИЕ: таблиц меньше ожидаемого — сверь с продом"
say "MARIADB PASS — дальше: 04-app-deploy.sh"
