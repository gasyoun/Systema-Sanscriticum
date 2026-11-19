<?php

declare(strict_types=1);

/*
 * ksh3 — обзорное занятие 3-го потока «Кашмирского шиваизма» (М. А. Ворошилов;
 * 29-10-2026 чт 14:00 МСК, бесплатное, «не в счет 16»; курс — 16 занятий по
 * чт 14:00 МСК с 05-11-2026, сбор голосов, оплата не открыта): /ga/-ключи ведут
 * на карточку курса (голос «Намерен участвовать» + расписание), маршрут на само
 * занятие — куратор. Креативы = гипотезы поста: c — предмет, t — преподаватель/
 * история курса, h — время/слот. Слот p не минтуется, пока оплата закрыта.
 */

$ksh3Campaign = 'kashmir_shaivism_open_oct_2026';

foreach (['c', 't', 'h'] as $creative) {
    $links["ksh3-ors-{$creative}"] = [
        'destination' => '/k/kasmirskii-sivaizm-3-potok-2026',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $ksh3Campaign,
            'utm_content' => "ksh3_{$creative}",
            'utm_term' => 'overview',
        ],
    ];
}

$storyCampaigns['ksh3'] = [
    'destination' => '/k/kasmirskii-sivaizm-3-potok-2026',
    'utm_campaign' => $ksh3Campaign,
    'utm_term' => 'overview',
];
