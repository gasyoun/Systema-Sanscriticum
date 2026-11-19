<?php

declare(strict_types=1);

/*
 * H6566 (MG 12-10-2026): «Стать добровольцем» — разметка санскритских
 * словарей Кёльна, вход через закрытую TG-группу. Кнопка ждун-бота ведёт
 * на прослойку /ga/volunteer, чтобы клик считался даже без входа в группу
 * (паттерн H6466/H6489); utm_source помечен zhdun_ — не сайт-атрибуция.
 */

$links['volunteer'] = [
    'destination' => 'https://t.me/+zl3hUJ632t03OGY6',
    'utm' => [
        'utm_source' => 'zhdun_share',
        'utm_medium' => 'bot_deeplink',
        'utm_campaign' => 'zhdun_volunteer_evergreen',
        'utm_content' => 'volunteer',
        'utm_term' => 'volunteer',
    ],
];

/*
 * vol — набор научных добровольцев (H6594; рулинг MG 13-10-2026: слаг
 * dobrovolec): анонс в «Оповещениях санскритян» ведёт в закрытую TG-группу
 * «Корпус: подготовка текстов» через пер-площадочный ключ — канон атрибуции
 * anons, каждая площадка свой источник (standing volunteer выше остаётся за
 * ждун-ботом). Ключ переименован из vol-ors-a до публикации — ленд по
 * рулингу MG 13-10-2026 «ленди без минта» (минт-клейм 9 отказов в утренней
 * волне Uprava); H### дополнить пост-минтом. Креатив — пост T1-стиля
 * (обновка 👻-поста 367 от 29-01-2025).
 */

$links['dobrovolec'] = [
    'destination' => 'https://t.me/+zl3hUJ632t03OGY6',
    'utm' => [
        'utm_source' => 'telegram_samskrte',
        'utm_medium' => 'owned_channel',
        'utm_campaign' => 'sanskrit_volunteer_evergreen',
        'utm_content' => 'volunteers_t1',
        'utm_term' => 'volunteer',
    ],
];
