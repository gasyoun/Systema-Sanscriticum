<?php

declare(strict_types=1);

/*
 * syn26 — открытое занятие «Синтаксиса санскрита» (курс 266, занятие #268
 * 25-10-2026 17:00 МСК; вс 17:00, блоки по 4 занятия 4 800 ₽, аудитория с
 * базой санскрита): маршрут попадания — куратор, /ga/-ключи ведут на карточку
 * курса. Креативы = гипотезы поста: c — программа, t — преподаватель/формат
 * занятия, h — время/слот; p — отдельный слот «условия/оплата» для поста,
 * где рядом стоит второй ключ (каноническая пара c+p, правило up26).
 */

$syn26Campaign = 'syntax_open_oct_2026';

foreach (['c', 't', 'h', 'p'] as $creative) {
    $links["syn26-ors-{$creative}"] = [
        'destination' => '/k/sintaksis-sanskrita',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $syn26Campaign,
            'utm_content' => "syn26_{$creative}",
            'utm_term' => 'advanced',
        ],
    ];
}
