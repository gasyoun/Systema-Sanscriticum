<?php

declare(strict_types=1);

/*
 | Карточки открытых занятий в @samskrte_bot (фото + «✅ Я иду» + нижнее
 | меню). Главный тумблер — features.open_lesson_cards (OFF по умолчанию).
 | Какие занятия идут в бот — галочка schedules.bot_card_enabled.
 */
return [
    // Пока список не пуст, карточки уходят ТОЛЬКО этим chat_id (через запятую) —
    // обкатка на своих перед включением на всех подписчиков.
    'test_chat_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPEN_LESSON_CARDS_TEST_CHAT_IDS', '')),
    ))),

    // Анонс: за сколько дней до занятия (самое раннее). Ежедневный прогон
    // берёт занятия через 2..announce_days дней, дедуп — одна отправка.
    'announce_days' => 3,

    // Занятие раньше этого часа МСК получает «утреннюю» карточку накануне
    // вечером (прогон 19:00), позже — утром в день занятия (прогон 09:00).
    'early_start_hour' => 12,

    // За сколько минут до начала пришлать ссылку Zoom нажавшим «Я иду».
    'zoom_lead_minutes' => 30,

    // «🎙 Ближайшие занятия» в меню: горизонт списка, дней.
    'list_horizon_days' => 14,

    // Нижнее меню (reply keyboard). Ключ — действие, значение — надпись.
    'menu' => [
        'lessons' => '🎙 Ближайшие занятия',
        'zhdun' => '⏳ Ждун',
        'site' => '🌐 Сайт',
        'enroll' => '✍️ Записаться',
    ],

    'site_url' => 'https://samskrte.ru',
    'enroll_url' => 'https://samskrte.ru/raspisanie',
];
