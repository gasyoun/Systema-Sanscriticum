<?php

declare(strict_types=1);

/*
 * vas26 — открытый урок «Медленного чтения Йога-васиштхи» (курс 334, занятие
 * #49 22-10-2026 12:00 МСК; чт 12:00, блоки по 4 занятия 4 800 ₽): маршрут
 * попадания на занятие — куратор (@rusamskrtam), /ga/-ключи ведут на карточку
 * курса. Креативы = гипотезы поста: c — программа, t — преподаватель/формат
 * занятия, h — время/слот; p — отдельный слот «условия/оплата» для поста,
 * где рядом стоит второй ключ (каноническая пара c+p, правило up26).
 *
 * Креатив r — рекап открытого урока 22-10 (как у san26-ors-r): маршрут ведет
 * на WP-статью записи урока на samskrtam.ru (rutube/vk/youtube + стенограмма),
 * а не на карточку курса. Внешний destination — как у t.me-хопов ниже:
 * флеш-цель Метрики на нем не ставится, клик пишется в anons_link_clicks.
 */

$vas26Campaign = 'vasishtha_open_oct_2026';

foreach (['c', 't', 'h', 'p'] as $creative) {
    $links["vas26-ors-{$creative}"] = [
        'destination' => '/k/yoga-vasistha',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $vas26Campaign,
            'utm_content' => "vas26_{$creative}",
            'utm_term' => 'slow_reading',
        ],
    ];
}

$links['vas26-ors-r'] = [
    'destination' => 'https://samskrtam.ru/kurs-joga-vasishtha/',
    'utm' => [
        'utm_source' => $channels['ors']['source'],
        'utm_medium' => $channels['ors']['medium'],
        'utm_campaign' => $vas26Campaign,
        'utm_content' => 'vas26_r',
        'utm_term' => 'slow_reading',
    ],
];
