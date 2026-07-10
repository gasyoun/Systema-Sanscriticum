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

    // H464 Phase 2 (revised H483 Phase 3b) — Day 1/2 drip content, sent as
    // Telegram messages by `marathon:deliver-due`. `{link}` is replaced by
    // `marathon:deliver-due` with the enrollee's personal tap-choice page
    // URL (magnet_token-keyed, H483) — kept as a template placeholder here,
    // not hardcoded in the command. Cyrillic only, no devanagari (H440 §1a).
    // HTML tags, not Markdown asterisks — TelegramDeliveryChannel::sendMessage
    // sends parse_mode=HTML (app/Services/Messaging/TelegramDeliveryChannel.php).
    'day1_message' => 'День 1. Санскрит роднее, чем кажется 👋'."\n\n"
        .'Пара минут на тап-выбор — без деванагари, без письма, просто узнавание.'."\n\n"
        .'{link}',

    'day2_message' => 'День 2. Как устроено санскритское слово 🧩'."\n\n"
        .'Корень + аффикс — на паре простых примеров. И в конце — оставьте свой '
        .'вопрос к живой консультации Дня 3.'."\n\n"
        .'{link}',

    // H483 Phase 3b — tap-choice recognition content for the Day 1/2 pages
    // (resources/views/marathon/day1.blade.php / day2.blade.php). Linear
    // steps, not the branching results{} shape ShopController::start() uses
    // for its master-quiz (recognition tasks don't route anywhere — each
    // answer just reveals an explanation and advances). 0-indexed `correct`.
    'day1_quiz' => [
        'steps' => [
            [
                'text' => 'Слово veda значит...',
                'opts' => ['Видеть', 'Знать', 'Течь'],
                'correct' => 1,
                'explain' => 'Верно! «Veda» — «знание». Тот же корень, что в русском «ведать», «весть», «невежда» — санскрит и русский родня, оба из одной индоевропейской семьи.',
            ],
            [
                'text' => 'А matar?',
                'opts' => ['Отец', 'Мать', 'Брат'],
                'correct' => 1,
                'explain' => 'Да! «Matar» — «мать». Слышите родство? bhratar — «брат» устроено так же.',
            ],
            [
                'text' => 'Bhratar — это...',
                'opts' => ['Сестра', 'Друг', 'Брат'],
                'correct' => 2,
                'explain' => 'Точно! Родство санскрита и русского — не совпадение, а общий индоевропейский предок.',
            ],
        ],
    ],

    'day2_quiz' => [
        'steps' => [
            [
                'text' => 'gam значит «идти». Что добавляется, чтобы получить «он идёт» — gacchati?',
                'opts' => ['Только окончание', 'Корень + показатель времени', 'Совсем другое слово'],
                'correct' => 1,
                'explain' => 'Верно! gacchati = корень gam + показатель настоящего времени. Санскритское слово почти всегда — корень + аффиксы.',
            ],
            [
                'text' => 'āgama значит «приход, писание». Какой в нём корень?',
                'opts' => ['gam', 'ā', 'ma'],
                'correct' => 0,
                'explain' => 'Да, тот же gam! И в saṃgati («встреча») — тоже он. Один корень, разные приставки — разный смысл.',
            ],
        ],
    ],
];
