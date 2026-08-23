#!/usr/bin/env bash
# activate_tiered_pricing.sh — H3331 §3 checklist, MG ruling 22-08-2026:
# витрина клуба переходит на ратифицированную лестницу Free/Basic/Club
# 01-09-2026 08:00 MSK (cron 07:50). Idempotent, self-disabling via marker.
#
# Шаги (docs/PREFLIGHT_CLUB_CHECKOUT_H3331_22-08-2026.md §3.2):
#   1. membership:classify-tiers --apply (expected 0/0 — dry 22-08 показал 0)
#   2. upsert 6 тарифов контракта (Basic ₽1000/2850/10200 + Club reprice 2000/5700/20400, курс 444)
#   3. MEMBERSHIP_TIERED=true + config:cache
#   4. membership:rehearse — при любом провале: флаг OFF (rollback config-only) + exit 1
#
# Ручной запуск вне расписания: bash scripts/activate_tiered_pricing.sh --force

set -uo pipefail
cd /var/www/html
LOG=storage/logs/tier_activation.log
MARKER=storage/app/membership/tier_activation_20260901.done

log(){ echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

if [ -f "$MARKER" ] && [ "${1:-}" != "--force" ]; then
    log "already activated (marker present) — nothing to do"
    exit 0
fi

rollback(){
    log "ROLLBACK: returning MEMBERSHIP_TIERED=false"
    sed -i 's/^MEMBERSHIP_TIERED=true/MEMBERSHIP_TIERED=false/' .env || true
    php artisan config:clear >/dev/null 2>&1 && php artisan config:cache >/dev/null 2>&1
}

log "=== tier activation start ==="

sudo -u www-data php artisan membership:classify-tiers --apply \
    --expected-memberships=0 --expected-tariffs=0 >>"$LOG" 2>&1 \
    || { log "FAIL classify-tiers"; rollback; exit 1; }
log "step1 classify OK"

sudo -u www-data php artisan tinker <<'PHP' >>"$LOG" 2>&1
$plan = [
    ['basic', 1, 'Базовый — месяц', '1000.00', 'Весь архив записей, свой темп. Оплата помесячно, продление ручное.'],
    ['basic', 3, 'Базовый — квартал', '2850.00', 'Три месяца со скидкой 5%. Продление ручное.'],
    ['basic', 12, 'Базовый — год', '10200.00', 'Год по цене десяти месяцев (−15%).'],
    ['club', 1, 'Клуб — месяц', '2000.00', 'Базовое плюс максимум практики и инструментов. Продление ручное.'],
    ['club', 3, 'Клуб — квартал', '5700.00', 'Три месяца Клуба со скидкой 5%.'],
    ['club', 12, 'Клуб — год', '20400.00', 'Год Клуба по цене десяти месяцев (−15%).'],
];
foreach ($plan as [$tier, $months, $title, $price, $desc]) {
    $t = \App\Models\Tariff::query()
        ->where('course_id', 444)
        ->where('membership_tier', $tier)
        ->where('membership_months', $months)
        ->first();
    if ($t) {
        $t->update(['price' => $price, 'title' => $title, 'description' => $desc, 'is_active' => true]);
        echo "updated {$tier} {$months}m -> {$price}".PHP_EOL;
    } else {
        \App\Models\Tariff::create([
            'course_id' => 444, 'title' => $title, 'type' => 'full',
            'price' => $price, 'description' => $desc, 'is_active' => true,
            'is_recording' => false, 'membership_months' => $months,
            'membership_tier' => $tier,
        ]);
        echo "created {$tier} {$months}m @ {$price}".PHP_EOL;
    }
}
echo 'matrix now: '.\App\Models\Tariff::whereNotNull('membership_tier')->count().PHP_EOL;
PHP
[ $? -eq 0 ] || { log "FAIL tariff upsert"; rollback; exit 1; }
log "step2 tariffs OK"

if ! grep -q '^MEMBERSHIP_TIERED=true$' .env; then
    sed -i '/^MEMBERSHIP_TIERED=/d' .env
    echo 'MEMBERSHIP_TIERED=true' >> .env
fi
php artisan config:clear >/dev/null 2>&1 && php artisan config:cache >/dev/null 2>&1
log "step3 flag ON"

REHEARSE=$(sudo -u www-data php artisan membership:rehearse 2>&1)
echo "$REHEARSE" >> "$LOG"
if ! echo "$REHEARSE" | grep -q 'РЕПЕТИЦИЯ: все выполненные шаги PASS'; then
    log "FAIL rehearse — rolling flag back"
    rollback
    exit 1
fi
log "step4 rehearse PASS"

touch "$MARKER"
chown www-data:www-data "$MARKER" 2>/dev/null || true
log "=== ACTIVATION COMPLETE: vitrine is Free/Basic/Club per contract ==="
