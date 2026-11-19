<?php

declare(strict_types=1);

/*
 * Публичные короткие ссылки кампаний. Контроллер сохраняет метки в сессии и
 * переводит посетителя на чистый адрес; параметры не показываются в URL.
 *
 * Разбивка на модули (YBQ, рулинг MG 11-11-2026 «разбить»): после блока bf11
 * файл вырос до 21 586 Б (5 397 токенов при лимите 5000) и пересёк бюджет
 * token-size-gate. Теперь файл — тонкий сборщик: общая таблица каналов,
 * явные require'ы модулей и return. Модули кампаний лежат в
 * resources/tracked_links/ — вне config/, иначе лоадер конфигов Laravel
 * (Finder, рекурсивный) подхватил бы их как tracked_links.<имя> и выполнил
 * без общей области видимости ($channels и пр.). Каждый модуль дописывает
 * свои ключи в $links / $storyCampaigns; новая кампания = новый модуль
 * в resources/tracked_links/ + строка require ниже.
 */

$channels = [
    'ors' => ['source' => 'telegram_samskrte', 'medium' => 'owned_channel'],
    'mg' => ['source' => 'telegram_marcisgasuns', 'medium' => 'owned_channel'],
    'is' => ['source' => 'telegram_indiaswami', 'medium' => 'paid_post'],
    'it' => ['source' => 'telegram_indiatoday', 'medium' => 'partner_post'],
    'vk' => ['source' => 'vk_senler', 'medium' => 'broadcast'],
    'samskrte' => ['source' => 'samskrte', 'medium' => 'crosslink'],
    'samskrtam' => ['source' => 'samskrtam', 'medium' => 'crosslink'],
];

$links = [];
$storyCampaigns = [];

require __DIR__.'/../resources/tracked_links/m26.php';
require __DIR__.'/../resources/tracked_links/up26.php';
require __DIR__.'/../resources/tracked_links/san26.php';
require __DIR__.'/../resources/tracked_links/bi26.php';
require __DIR__.'/../resources/tracked_links/vas26.php';
require __DIR__.'/../resources/tracked_links/syn26.php';
require __DIR__.'/../resources/tracked_links/ksh3.php';
require __DIR__.'/../resources/tracked_links/demo.php';
require __DIR__.'/../resources/tracked_links/zhdun.php';
require __DIR__.'/../resources/tracked_links/vanons.php';
require __DIR__.'/../resources/tracked_links/volunteer.php';
require __DIR__.'/../resources/tracked_links/kal26.php';
require __DIR__.'/../resources/tracked_links/bf11.php';
require __DIR__.'/../resources/tracked_links/ling_stories.php';

return [
    'session_key' => 'tracked_link_attribution',
    'links' => $links,
    'story_campaigns' => $storyCampaigns,
    'story_accounts' => [
        'mg' => 'telegram_marcisgasuns',
        'rs' => 'telegram_rusamskrtam',
    ],
];
