#!/usr/bin/env bash
# H5443 (P1): доказательство денежного ядра на настоящей MariaDB — только scratch-база.
#
# Запуск: на прод-хосте от root, из распакованной копии ветки (с dev-vendor),
# НЕ из /var/www/html. Прод-.env не нужен и не читается: подключение — root по
# unix-сокету к отдельной базе h5443_scratch, которая в конце удаляется.
#
#   bash scripts/h5443_ledger_mariadb_proof.sh [--with-legacy-copy]
#
# Локальный режим — любая MariaDB по TCP (например, docker mariadb:11.8.6):
#   H5443_TCP=127.0.0.1:33066 H5443_ROOT_PW=scratch \
#   H5443_MYSQL="docker exec -i h5443db mariadb -uroot -pscratch" \
#   bash scripts/h5443_ledger_mariadb_proof.sh
# В локальном режиме шаг 1 (pretend против живой схемы) заменён pretend на scratch.
#
# --with-legacy-copy: копирует payments/users/courses/teachers боевой базы в
# scratch (данные остаются на хосте и удаляются вместе с базой) и гоняет
# money:ledger-backfill-report в обоих режимах. Отчёт печатает только id/суммы.
set -euo pipefail

DB=h5443_scratch
LIVE=laravel
MIG=database/migrations/2026_09_24_150000_create_money_ledger_core_tables.php
sql() { ${H5443_MYSQL:-mysql} "$@"; }

if [ -n "${H5443_TCP:-}" ]; then
    export DB_CONNECTION=mysql DB_HOST="${H5443_TCP%:*}" DB_PORT="${H5443_TCP#*:}" DB_SOCKET= DB_DATABASE="$DB" DB_USERNAME=root DB_PASSWORD="${H5443_ROOT_PW:-}"
else
    export DB_CONNECTION=mysql DB_HOST=localhost DB_SOCKET="$(sql -N -e 'SELECT @@socket')" DB_DATABASE="$DB" DB_USERNAME=root DB_PASSWORD=
fi
export APP_ENV=local APP_DEBUG=false APP_KEY=base64:2fl+KtvkdphvQyEfjOzf3mJCEJnA8otCLK5LpM/V8Xw=
export CACHE_STORE=array CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array MAIL_MAILER=array
export TELESCOPE_ENABLED=false PULSE_ENABLED=false LOG_CHANNEL=stderr LOG_LEVEL=error MONEY_LEDGER_CORE=false

triggers() { sql -N -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$DB' AND trigger_name LIKE 'money\\_%'"; }
tables() { sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name LIKE 'money\\_%'"; }
cleanup() { sql -e "DROP DATABASE IF EXISTS $DB"; echo "== cleanup: $DB dropped"; }
trap cleanup EXIT

sql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
echo "== MariaDB $(sql -N -e 'SELECT VERSION()') · log_bin=$(sql -N -e 'SELECT @@log_bin') · $(sql -N -e 'SELECT @@transaction_isolation')"

if [ -z "${H5443_TCP:-}" ]; then
echo "== 1. dry run against the LIVE schema ($LIVE): migrate --pretend (no statement executed)"
LIVE_BEFORE=$(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LIVE'")
DB_DATABASE=$LIVE php artisan migrate --pretend --path="$MIG" > /tmp/h5443_pretend.sql
echo "   pretend lines: $(wc -l < /tmp/h5443_pretend.sql) · CREATE TABLE: $(grep -c 'create table' /tmp/h5443_pretend.sql) · CREATE TRIGGER: $(grep -c 'CREATE TRIGGER' /tmp/h5443_pretend.sql)"
echo "   live tables before/after: $LIVE_BEFORE/$(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LIVE'") · live money_* tables: $(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LIVE' AND table_name LIKE 'money\\_%'")"
fi

echo "== 2. full migrate on scratch"
START=$(date +%s)
php artisan migrate --force > /tmp/h5443_migrate.log
echo "   migrations: $(grep -c DONE /tmp/h5443_migrate.log) in $(( $(date +%s) - START ))s · money tables=$(tables) · triggers=$(triggers)"
PAY_COLS=$(sql -N -e "SELECT MD5(GROUP_CONCAT(column_name, column_type ORDER BY ordinal_position)) FROM information_schema.columns WHERE table_schema='$DB' AND table_name='payments'")

echo "== 3. rollback → tables=$(php artisan migrate:rollback --force --path="$MIG" >/dev/null && tables) triggers=$(triggers)"
echo "   payments schema unchanged: $([ "$PAY_COLS" = "$(sql -N -e "SELECT MD5(GROUP_CONCAT(column_name, column_type ORDER BY ordinal_position)) FROM information_schema.columns WHERE table_schema='$DB' AND table_name='payments'")" ] && echo yes || echo NO)"
php artisan migrate --pretend --force --path="$MIG" > /tmp/h5443_pretend.sql
echo "   dry run (pretend) on scratch: CREATE TABLE $(grep -ci 'create table' /tmp/h5443_pretend.sql) · CREATE TRIGGER $(grep -c 'CREATE TRIGGER' /tmp/h5443_pretend.sql) · tables after pretend=$(tables)"
echo "== 4. re-migrate → tables=$(php artisan migrate --force --path="$MIG" >/dev/null && tables) triggers=$(triggers)"

race() {
    local mode=$1 id barrier
    id=$(php scripts/h5443_ledger_concurrency_probe.php setup)
    barrier=$(php -r 'printf("%.3f", microtime(true) + 3);')
    php scripts/h5443_ledger_concurrency_probe.php "$mode" "$id" "race:$mode:a:$id" 6000 "$barrier" > "/tmp/h5443_race_a" &
    php scripts/h5443_ledger_concurrency_probe.php "$mode" "$id" "race:$mode:b:$id" 6000 "$barrier" > "/tmp/h5443_race_b" &
    wait
    echo "   $mode race on receipt $id (10000, two refunds of 6000 each):"
    sed 's/^/      /' /tmp/h5443_race_a /tmp/h5443_race_b
    echo "      family net = $(sql -N -e "SELECT SUM(amount_kopecks) FROM $DB.money_movements WHERE id=$id OR cap_anchor_id=$id")"
}
echo "== 5. concurrency (both participants hold an old REPEATABLE READ snapshot)"
race service
race raw
echo "   integrity: $(php scripts/h5443_ledger_concurrency_probe.php check)"

echo "== 6. ledger tests on MariaDB (RefreshDatabase = migrate:fresh on scratch)"
vendor/bin/phpunit tests/Feature/Ledger 2>&1 | grep -viE 'deprecat' | tail -6

if [ "${1:-}" = "--with-legacy-copy" ]; then
    echo "== 7. backfill report on a copy of live legacy tables (stays on this host)"
    php artisan migrate:fresh --force >/dev/null
    mysqldump --single-transaction --skip-triggers "$LIVE" payments users courses teachers | sql "$DB"
    echo "   copied payments=$(sql -N -e "SELECT COUNT(*) FROM $DB.payments")"
    php artisan money:ledger-backfill-report --json=/tmp/h5443_backfill_plan.json --limit=0 | tail -30
    php artisan money:ledger-backfill-report --shadow --json=/tmp/h5443_backfill_shadow.json --limit=0 | tail -30
    echo "   money_* rows after shadow: $(sql -N -e "SELECT (SELECT COUNT(*) FROM $DB.money_movements)+(SELECT COUNT(*) FROM $DB.money_allocations)+(SELECT COUNT(*) FROM $DB.money_obligations)")"
fi
