<?php

declare(strict_types=1);

/*
 * vanons — вечнозелёный ключ видео-анонса (H6547): сторис персоны с видео из
 * учебных чатов (лейн H6546) ведут сюда. Один стоящий ключ, не кампания —
 * пер-видео креативы только если MG попросит разбивку атрибуции.
 *
 * H6574: видео-анонс на YouTube Shorts (канал записей) — раздельная
 * атрибуция сёрфейса, тот же вечнозелёный паттерн, что vanons-tg-v1.
 *
 * H6574: видео-анонс на VK-стене — раздельная атрибуция сёрфейса.
 */

$links['vanons-tg-v1'] = [
    'destination' => '/online',
    'utm' => [
        'utm_source' => 'telegram_story_rusamskrtam',
        'utm_medium' => 'persona_story',
        'utm_campaign' => 'video_anons_evergreen',
        'utm_content' => 'vanons_v1',
        'utm_term' => 'video',
    ],
];

$links['vanons-yt-v1'] = [
    'destination' => '/online',
    'utm' => [
        'utm_source' => 'youtube_shorts',
        'utm_medium' => 'shorts_description',
        'utm_campaign' => 'video_anons_evergreen',
        'utm_content' => 'vanons_yt_v1',
        'utm_term' => 'video',
    ],
];

$links['vanons-vk-v1'] = [
    'destination' => '/online',
    'utm' => [
        'utm_source' => 'vk_wall',
        'utm_medium' => 'wall_post',
        'utm_campaign' => 'video_anons_evergreen',
        'utm_content' => 'vanons_vk_v1',
        'utm_term' => 'video',
    ],
];

$storyCampaigns['vanons'] = [
    'destination' => '/online',
    'utm_campaign' => 'video_anons_evergreen',
    'utm_term' => 'video',
];
