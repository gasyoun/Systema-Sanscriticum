<?php

declare(strict_types=1);

/*
 * san26 — волна набора «Философия санкхьи» (курс 459). С 18-10-2026 кампания
 * переведена из кворума в rolling: кворум 8 намерений снят как условие старта
 * (осталась исторической записью ждуна — карточка 19 в статусе scheduled),
 * группа 154 стартует 19-10 в 18:00, оплата открыта по блокам (тарифы
 * 5126–5129, 6 000 ₽ за блок) и остаётся открытой после старта — в режиме
 * донабора, пока идут занятия. Креативы = гипотезы поста: c — программа,
 * t — преподаватель, h — время/слот; платёжного креатива -p пока нет.
 *
 * Креатив r — рекап открытой лекции 19-10 (старт группы 154): маршрут ведет
 * на WP-статью записи лекции на samskrtam.ru (rutube/vk/youtube + стенограмма),
 * а не на карточку курса. Внешний destination — как у t.me-хопов ниже:
 * флеш-цель Метрики на нем не ставится, клик пишется в anons_link_clicks.
 */

$san26Campaign = 'sankhya_kurs_oct_2026';

foreach (['c', 't', 'h'] as $creative) {
    $links["san26-ors-{$creative}"] = [
        'destination' => '/k/filosofiia-sankhi',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $san26Campaign,
            'utm_content' => "san26_{$creative}",
            'utm_term' => 'philosophy',
        ],
    ];
}

$links['san26-ors-r'] = [
    'destination' => 'https://samskrtam.ru/kurs-filosofiya-sankhi/',
    'utm' => [
        'utm_source' => $channels['ors']['source'],
        'utm_medium' => $channels['ors']['medium'],
        'utm_campaign' => $san26Campaign,
        'utm_content' => 'san26_r',
        'utm_term' => 'philosophy',
    ],
];
