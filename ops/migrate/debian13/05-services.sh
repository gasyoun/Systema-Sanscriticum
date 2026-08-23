#!/usr/bin/env bash
# 05-services.sh — nginx vhost, php-fpm пул, worker-unit, cron + sbin из репо.
# nginx-конфиг берётся из снапшота (/etc/nginx) если он есть; шаблон — fallback.
set -euo pipefail

STAGE="${STAGE:-/srv/migrate}"
APP_DIR="${APP_DIR:-/var/www/html/app}"
PHP_VER="8.3"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
KIT_DIR="$(cd "$(dirname "$0")/.." && pwd)"   # ops/migrate

say() { printf '[services] %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }

say "1/6 php-fpm pool (сокет /run/php/php${PHP_VER}-fpm-migrate.sock)"
install -m 644 "${KIT_DIR}/templates/www-migrate.fpm.conf" \
    "/etc/php/${PHP_VER}/fpm/pool.d/migrate.conf"

say "2/6 nginx: приоритет — конфиг из снапшота; иначе шаблон"
if [ -f "${STAGE}/paths.nginx" ]; then
    NGX_SRC=$(cat "${STAGE}/paths.nginx")
    rsync -a --backup --suffix=.pre-migrate "${NGX_SRC}/sites-available/" /etc/nginx/sites-available/ 2>/dev/null || true
    rsync -a "${NGX_SRC}/nginx.conf" /etc/nginx/nginx.conf 2>/dev/null || true
    say "восстановлены sites-available из снапшота (.pre-migrate бэкапы перезаписанного)"
    # пути старого бокса → новый app-dir, если отличаются
    grep -rl '/var/www/html' /etc/nginx/sites-available 2>/dev/null | while read -r f; do
        sed -i 's#/var/www/html/#'"${APP_DIR}"'/#g' "$f"
    done
else
    install -m 644 "${KIT_DIR}/templates/nginx-samskrte.conf" \
        /etc/nginx/sites-available/samskrte.conf
fi
ln -sfn /etc/nginx/sites-available/samskrte.conf /etc/nginx/sites-enabled/samskrte.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t

say "3/6 systemd worker"
install -m 644 "${KIT_DIR}/templates/laravel-worker.service" /etc/systemd/system/laravel-worker.service
sed -i "s#__APP_DIR__#${APP_DIR}#g; s#__DEPLOY_USER__#${DEPLOY_USER}#g" \
    /etc/systemd/system/laravel-worker.service
systemctl daemon-reload && systemctl enable --now laravel-worker.service

say "4/6 sbin-скрипты из репо (auto-deploy/watchdog/schedule/memwatch)"
for s in systema-auto-deploy-run.sh systema-schedule-run.sh systema-watchdog-run.sh memwatch.sh; do
    src="${APP_DIR}/scripts/server_guards/sbin/${s}"
    [ -f "$src" ] && install -m 755 "$src" "/usr/local/sbin/${s}" && say "  ${s}"
done
# restic-скрипты бэкапа + db-dump
for s in systema-restic-run.sh systema-restic-forget.sh systema-db-dump.sh systema-restic-s3-verify.sh; do
    [ -f "${APP_DIR}/ops/backup/${s}" ] && install -m 755 "${APP_DIR}/ops/backup/${s}" "/usr/local/sbin/${s}"
done

say "5/6 cron root"
install -m 644 "${KIT_DIR}/templates/cron-root" /etc/cron.d/systema-migrate
sed -i "s#__APP_DIR__#${APP_DIR}#g" /etc/cron.d/systema-migrate

say "6/6 restic на новом боксе: repo-path и таймеры (SFTP через LAN к .91)"
if [ ! -s /root/.restic-pass ]; then
    say "*** GATE *** положи пароль в /root/.restic-pass (chmod 600) — таймеры включу после"
else
    systemctl enable --now restic-backup.timer 2>/dev/null \
        || say "restic-backup.timer не найден — unit'ы приедут с деплоем ops/backup/*.service"
fi
systemctl restart "php${PHP_VER}-fpm" nginx 2>/dev/null || true

say "SERVICES PASS — дальше: 06-verify-cutover.sh (без --cutover)"
