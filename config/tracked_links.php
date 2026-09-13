<?php

declare(strict_types=1);

/*
 * Публичные короткие ссылки кампаний. Контроллер сохраняет метки в сессии и
 * переводит посетителя на чистый адрес; параметры не показываются в URL.
 */
$campaign = 'grammar_gasuns_autumn_2026';
$channels = [
    'ors' => ['source' => 'telegram_samskrte', 'medium' => 'owned_channel'],
    'mg' => ['source' => 'telegram_marcisgasuns', 'medium' => 'owned_channel'],
    'is' => ['source' => 'telegram_indiaswami', 'medium' => 'paid_post'],
    'it' => ['source' => 'telegram_indiatoday', 'medium' => 'partner_post'],
    'vk' => ['source' => 'vk_senler', 'medium' => 'broadcast'],
    'samskrte' => ['source' => 'samskrte', 'medium' => 'crosslink'],
    'samskrtam' => ['source' => 'samskrtam', 'medium' => 'crosslink'],
    'personal' => ['source' => 'marcisgasuns_personal', 'medium' => 'personal_page'],
];
$links = [];

foreach ($channels as $channel => $attribution) {
    foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
        $links["m26-{$channel}-{$creative}"] = [
            'destination' => '/online',
            'utm' => [
                'utm_source' => $attribution['source'],
                'utm_medium' => $attribution['medium'],
                'utm_campaign' => $campaign,
                'utm_content' => "g26_{$creative}",
                'utm_term' => 'beginner',
            ],
        ];
    }
}

return [
    'session_key' => 'tracked_link_attribution',
    'links' => $links,
];
