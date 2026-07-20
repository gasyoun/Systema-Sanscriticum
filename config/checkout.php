<?php

declare(strict_types=1);

return [
    /*
     | Резервный порог (в минутах) для будущей более агрессивной реакции на
     | новые (не legacy) pending-чекауты без timed промо-брони. Пока НЕ
     | используется payments:expire-stale-checkouts напрямую — единственный
     | сейчас действующий порог для payment_link_expires_at IS NULL строк это
     | legacy_pending_days ниже (H1358, см. "## Decisions taken" в handoff:
     | легаси null-expiry строки — подавляющее большинство обычных чекаутов,
     | а AuditCheckoutIntegrity::legacyPendingPromoReservations прямо требует
     | ручной сверки перед их отменой, отсюда широкое legacy-окно вместо
     | узкого). Держим как явный конфиг-ключ на случай, если позже введём
     | отдельный быстрый порог для НЕ-legacy null-expiry строк.
     | CHECKOUT_STALE_PENDING_MINUTES в .env, дефолт 180.
     */
    'stale_pending_minutes' => (int) env('CHECKOUT_STALE_PENDING_MINUTES', 180),

    /*
     | Сколько дней висящий pending-чекаут БЕЗ timed промо-брони
     | (payment_link_expires_at IS NULL — обычные чекауты, и промо-чекауты,
     | сделанные при выключенном checkout_promo_reservations) ещё держит
     | забронированные прану/реферальный кредит/зачтённый депозит, прежде чем
     | payments:expire-stale-checkouts сочтёт его брошенным и провалит.
     | Чекауты с активной timed промо-бронью используют вместо этого
     | PromoCode::WEBHOOK_BUFFER_MINUTES — см. PromoCode::activeReservationCount.
     | CHECKOUT_LEGACY_PENDING_DAYS в .env, дефолт 30.
     */
    'legacy_pending_days' => (int) env('CHECKOUT_LEGACY_PENDING_DAYS', 30),
];
