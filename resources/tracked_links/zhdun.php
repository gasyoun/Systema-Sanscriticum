<?php

declare(strict_types=1);

/*
 * zhdun — трекинговые прослойки для deep-links бота на один курс ждуна
 * (H6466/H6467, MG 11-10-2026: «отслеживать кто вообще шёл, пусть и не
 * дошёл»): /ga/zhdun-<slug> → t.me/samskrte_bot?start=wl_<slug>. Каждый
 * клик пишется в anons_link_clicks с utm_content = <slug>, даже если
 * человек бота не открыл. Список курсов синхронизируется с is_listed
 * course_waitlist_items; utm_source помечен zhdun_, чтобы клики прослойки
 * не смешивались с сайт-атрибуцией.
 */

$zhdunCourses = [
    'likbez-po-lingvistike',
    'lingvisticheskie-zadachi',
    'kalligrafiya-devanagari',
    'nachalnyi-sanskrit-litvinenko',
    'gitarthasangraha-abhinavagputy',
    'filosofiya-sankhyi',
    'indiiskii-epos',
    'kashmirskii-shivaizm',
    'putevoditel-po-buddizmu',
    'bxagavadgita-po-rukopisyam',
    'arkhitektura-i-iskusstvo-buddizma',
    'yoga-i-tantra',
    'grammatika-sanskrita-jan2027',
    'kosmografiya',
];

foreach ($zhdunCourses as $zhdunSlug) {
    // H6489 (MG 11-10-2026): ключ = голый <slug> без префикса zhdun- —
    // «чересчур длинные» ссылки; коллизий с m26-/up26-/demo-/story-ключами
    // нет (слаги курсов на эти префиксы не начинаются и story-regex не
    // матчатся).
    $links[$zhdunSlug] = [
        'destination' => 'https://t.me/samskrte_bot?start=wl_'.$zhdunSlug,
        'utm' => [
            'utm_source' => 'zhdun_share',
            'utm_medium' => 'bot_deeplink',
            'utm_campaign' => 'zhdun_waitlist_evergreen',
            'utm_content' => $zhdunSlug,
            'utm_term' => 'waitlist',
        ],
    ];
}
