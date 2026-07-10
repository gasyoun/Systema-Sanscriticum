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

    // H464 Phase 2 — Day 1/2 drip content, sent as Telegram messages by
    // `marathon:deliver-due`. Self-contained recognition text (question +
    // answer in the same message, "ноль производства" per MG's all-zero-
    // cohort ruling in H440 §1a) — no reply/interaction is required or
    // tracked. Cyrillic only, no devanagari. Plain config values, not
    // env()-wrapped like the scalars above — long copy doesn't belong in
    // .env, but it is still centralized here, not hardcoded in the command.
    // HTML tags, not Markdown asterisks — TelegramDeliveryChannel::sendMessage
    // sends parse_mode=HTML (app/Services/Messaging/TelegramDeliveryChannel.php).
    'day1_message' => 'День 1. Санскрит роднее, чем кажется 👋'."\n\n"
        .'Слово <b>veda</b> — «знание». Узнаёте корень? Это тот же корень, что в '
        .'русском «ведать», «весть», «невежда» — санскрит и русский родня, оба из '
        .'одной индоевропейской семьи.'."\n\n"
        .'Ещё пример: <b>matar</b> — «мать». <b>bhratar</b> — «брат». Слышите похожесть?'."\n\n"
        .'Завтра — как устроено само слово: корень + приставка/суффикс, на паре '
        .'простых примеров. 15 минут, в своём темпе.',

    'day2_message' => 'День 2. Как устроено санскритское слово 🧩'."\n\n"
        .'Санскритское слово — почти всегда корень + аффиксы. Пример: <b>gam</b> '
        .'(«идти») → <b>gacchati</b> («он идёт») — корень <b>gam</b> + показатель '
        .'настоящего времени. Тот же корень виден в словах <b>āgama</b> («приход, '
        .'писание») и <b>saṃgati</b> («встреча»).'."\n\n"
        .'Это и есть то, что откроет вам санскритский текст: не запоминать тысячи '
        .'слов отдельно, а видеть корни и узнавать родню.'."\n\n"
        .'День 3 — живая консультация: разберём именно ваш вопрос о том, с чего '
        .'начать. Приходите с вопросом — сбор вопросов к эфиру откроется отдельно.',
];
