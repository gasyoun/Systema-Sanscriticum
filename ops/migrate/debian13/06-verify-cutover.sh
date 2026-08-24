#!/usr/bin/env bash
# 06-verify-cutover.sh — pre-DNS верификация; с --cutover: certbot + финальный smoke.
# *** GATE ***: переключение DNS делает MG в reg.ru ВРУЧНУЮ после зелёной верификации.
set -euo pipefail

DOMAIN="${DOMAIN:-samskrte.ru}"
NEW_IP="${NEW_IP:-$(curl -s4 ifconfig.me || hostname -I | awk '{print $1}')}"
APP_DIR="${APP_DIR:-/var/www/html/app}"
PHP_VER="8.3"
MODE="${1:-verify}"

say() { printf '[verify] %s\n' "$*"; }
fail() { printf '[verify][FAIL] %s\n' "$*" >&2; exit 1; }

check() { # check <описание> <команда...>
    local label="$1"; shift
    if "$@" >/dev/null 2>&1; then say "OK   ${label}"; else fail "${label}"; fi
}

if [ "$MODE" != "--cutover" ]; then
    say "=== ПРЕД-ПЕРЕКЛЮЧЕНИЕ (Host-header против локального nginx) ==="
    check "health endpoint"        curl -s -H "Host: ${DOMAIN}" "http://127.0.0.1/health"
    check "главная отдаёт 200"     sh -c "curl -s -o /dev/null -w '%{http_code}' -H 'Host: ${DOMAIN}' http://127.0.0.1/ | grep -q '^2'"
    check "/mecenaty рендерится"   sh -c "curl -s -H 'Host: ${DOMAIN}' http://127.0.0.1/mecenaty | grep -q 'mecenaty/donate'"
    check "страница клуба живая"   sh -c "test \$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: ${DOMAIN}' http://127.0.0.1/klub) -lt 500"
    check "artisan about без FAIL" sh -c "! php artisan about --json 2>/dev/null | grep -qi '\"FAIL\"'"
    check "worker активен"         systemctl is-active laravel-worker.service
    check "redis отвечает"         redis-cli ping
    check "MariaDB таблицы"        mysql -N laravel -e 'SELECT COUNT(*) FROM users;'
    queue_jobs=$(mysql -N laravel -e 'SELECT COUNT(*) FROM jobs;' 2>/dev/null || echo 0)
    say "очередь: ${queue_jobs} отложенных задач (не блокер)"
    say ""
    say "=== *** GATE ***: если всё OK — MG переключает A-запись samskrte.ru → ${NEW_IP} в reg.ru,"
    say "затем запускает: $0 --cutover"
    exit 0
fi

say "=== CUTOVER: ожидаем, что DNS уже указывает на этот бокс ==="
resolved=$(getent hosts "${DOMAIN}" | awk '{print $1}' | head -1)
[ "$resolved" = "$NEW_IP" ] || fail "DNS ${DOMAIN} = ${resolved}, а не ${NEW_IP} — переключи A-запись и подожди TTL"

say "certbot TLS"
certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --non-interactive --agree-tos --redirect \
    ${CERTBOT_EMAIL:+-m "${CERTBOT_EMAIL}"} || fail "certbot не выпустил сертификат"

say "финальный smoke по HTTPS"
check "https главная"          sh -c "curl -s -o /dev/null -w '%{http_code}' https://${DOMAIN}/ | grep -q '^2'"
check "https /mecenaty форма"  sh -c "curl -s https://${DOMAIN}/mecenaty | grep -q 'mecenaty/donate'"
check "https health"           curl -s "https://${DOMAIN}/health"
check "TLS срок"               sh -c "echo | openssl s_client -connect ${DOMAIN}:443 2>/dev/null | openssl x509 -noout -enddate"

say "CUTOVER PASS — сайт на новом боксе. Не забудь Фазу 7 из RUNBOOK.md (Better Stack, restic-target, .92 standby)."
