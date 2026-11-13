<?php

declare(strict_types=1);

/*
 * kal26 — набор на «Каллиграфию деванагари (2026)» (курс 372, карточка ждуна 16
 * в статусе collecting, старт по набору): расписания нет, при кворуме 8
 * намерений автоматически открывается оплата (тарифы «весь курс» 9 600 ₽ и
 * блоки 1–2 по 4 800 ₽ сейчас неактивны). Ждун-оверлей /ga/kalligrafiya-devanagari
 * (zhdun_share → бот) — отдельная полоса шаринга, кампания ведёт на карточку
 * курса. Креативы = гипотезы поста: c — программа, t — преподаватель
 * (Ольга Литвиненко), v — механика голосования.
 */

$kal26Campaign = 'kalligrafia_devanagari_2026';

foreach (['c', 't', 'v'] as $creative) {
    $links["kal26-ors-{$creative}"] = [
        'destination' => '/k/kalligrafiia-devanagari-2026',
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $kal26Campaign,
            'utm_content' => "kal26_{$creative}",
            'utm_term' => 'devanagari',
        ],
    ];
}
