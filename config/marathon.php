<?php

/*
|--------------------------------------------------------------------------
| 3-day diagnostic marathon — «Консультация по онлайн-курсам ОРС» (H440)
|--------------------------------------------------------------------------
| MG decisions locked 09-07-2026, see Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md
| §8. Parameterized here per the handoff's explicit instruction — none of
| these are hardcoded in the controller/view.
*/

return [
    // Public landing page slug (LandingPage row created via Filament admin,
    // not seeded here — matches how every other production landing is set up).
    'landing_slug' => env('MARATHON_LANDING_SLUG', 'konsultaciya-po-onlayn-kursam'),

    // Paid track «с проверкой» — tripwire price, ₽.
    'paid_track_price' => (int) env('MARATHON_PAID_TRACK_PRICE', 500),

    // Coupon on the first course purchase after the marathon (MG decision).
    'coupon_amount' => (int) env('MARATHON_COUPON_AMOUNT', 1000),

    // Live consultation host (Day 3 Zoom, single live session — not MG's
    // config/investment.php-style discount rate, this is display copy only).
    'host_name' => env('MARATHON_HOST_NAME', 'к.ф.н. М.Ю. Гасунс'),

    // Delivery channel — Telegram, NOT the internal Systema UI (MG decision).
    'telegram_channel_url' => env('MARATHON_TELEGRAM_CHANNEL_URL', 'https://t.me/samskrte'),

    // Day-3 warm-tail length in days — median time-to-purchase from CUSTDEV_2026.md.
    'warm_tail_days' => (int) env('MARATHON_WARM_TAIL_DAYS', 13),
];
