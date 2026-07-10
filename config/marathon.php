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

    // H447 §5 — leaderboard A/B. Arm assignment (50/50, fixed at enrolment on
    // marathon_enrollments.ab_arm) ALWAYS runs so the experiment has data.
    // This flag gates only the ACTUAL UN-MASKING for arm B — it un-masks
    // real student names to peers, which needs an explicit enrolment-consent
    // decision (see Uprava/docs/SARASWATI_TRAINERS_2026.md §5 guard, and run
    // /publish-safety-check before flipping this). DEFAULT OFF — do not
    // change without that sign-off. While off, every arm (A and B alike)
    // sees the name-masked leaderboard.
    'leaderboard_unmask_enabled' => (bool) env('MARATHON_LEADERBOARD_UNMASK_ENABLED', false),

    // Day-3 warm-tail length in days — median time-to-purchase from CUSTDEV_2026.md.
    'warm_tail_days' => (int) env('MARATHON_WARM_TAIL_DAYS', 13),

    // H487 Phase 5 — the Day-3 live consultation is ONE shared Schedule row
    // (app/Models/Schedule.php), created/edited by MG through the existing
    // Filament ScheduleResource (no course_id/group_id — a standalone
    // session, same pattern as Course::trialSchedule()). Nothing activates
    // until this is set — see DEPLOY_QUEUE.md.
    'schedule_id' => env('MARATHON_SCHEDULE_ID'),

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

    // H487 Phase 5 — Day-3 messages, sent once by marathon:deliver-due when
    // currentDay() >= 3. {date}/{link}/{host} are interpolated by the command
    // (date/link from the configured Schedule row, host from host_name above)
    // — kept as template placeholders here, not re-reading env() a second
    // time. Paid track gets the live join link; free track gets told a
    // recording follows (H440 §3 item 6 — не ежедневный эфир, одна консультация).
    'day3_message_paid' => 'День 3 — живая консультация 🎥'."\n\n"
        .'{date}, ведёт {host}. Ваш вопрос уже у нас — разберём его лично.'."\n\n"
        .'{link}',

    'day3_message_free' => 'День 3 — живая консультация 🎥'."\n\n"
        .'{date} пройдёт живая консультация с {host}. На бесплатном треке — запись '
        .'после эфира, мы пришлём ссылку сюда же.',

    'recording_message' => 'Запись консультации готова 🎬'."\n\n"
        .'{link}',
];
