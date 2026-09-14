#!/usr/bin/env bash
# 01-bootstrap.sh — Debian 13 (trixie): базовая система под Laravel-прод.
# ВАЖНО: trixie несёт PHP 8.4; прод требует ^8.3 → ставим строго php8.3 с sury.org.
# Идемпотентен: повторный запуск доводит, а не ломает.
set -euo pipefail

PHP_VER="8.3"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="${APP_DIR:-/var/www/html}"
RESTIC_SOURCE_HOST="${RESTIC_SOURCE_HOST:-193.232.229.91}"

say() { printf '[bootstrap] %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }
[ -r /etc/debian_version ] || { echo "это не Debian" >&2; exit 1; }
. /etc/os-release
case "${VERSION_ID:-}" in 13) ;; *) say "ВНИМАНИЕ: VERSION_ID=${VERSION_ID:-?} — kit писался под Debian 13";; esac

say "apt update + базовые пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get -y install ca-certificates curl gnupg lsb-release

say "sury-репозиторий PHP ${PHP_VER} (в трикси из коробки PHP 8.4)"
install -d -m 0755 /etc/apt/keyrings
if [ ! -f /etc/apt/keyrings/deb.sury.org-php.gpg ]; then
    curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /etc/apt/keyrings/deb.sury.org-php.gpg
fi
echo "deb [signed-by=/etc/apt/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
    > /etc/apt/sources.list.d/sury-php.list
apt-get update -qq

say "стек: nginx, MariaDB, PHP ${PHP_VER}-fpm + расширения, redis, restic, certbot"
apt-get -y install \
    nginx mariadb-server mariadb-client \
    "php${PHP_VER}-fpm" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" \
    "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-gd" "php${PHP_VER}-intl" \
    "php${PHP_VER}-bcmath" "php${PHP_VER}-redis" "php${PHP_VER}-opcache" \
    redis-server unzip git restic certbot python3-certbot-nginx rsync jq

if ! command -v composer >/dev/null; then
    say "composer 2"
    curl -fsSL https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer --quiet
    composer --version
else
    say "composer уже есть: $(composer --version | head -1)"
fi

say "php-fpm: память-дружелюбный opcache (prod ~14GB RAM был на старом боксе)"
FPMZ="/etc/php/${PHP_VER}/fpm/conf.d/90-migrate.ini"
cat > "$FPMZ" <<'EOF'
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
memory_limit=512M
EOF

say "пользователь ${DEPLOY_USER} и каталоги"
id -u "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR"
install -d -m 750 -o root -g root /srv/migrate

say "systemd timers на будущее (restic включается в фазе 05)"
systemctl enable --now mariadb >/dev/null 2>&1 || true
systemctl enable --now nginx   >/dev/null 2>&1 || true
systemctl enable --now "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
systemctl enable --now redis-server >/dev/null 2>&1 || true

php -v | head -1
say "BOOTSTRAP PASS — дальше: 02-fetch-restore.sh"
