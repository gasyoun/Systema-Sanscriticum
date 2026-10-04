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
];
$links = [];

foreach ($channels as $channel => $attribution) {
    foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
        $links["m26-{$channel}-{$creative}"] = [
            'destination' => '/online/kursy/grammatika-gasuns-2026',
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

$links['m26-schedule-c'] = [
    'destination' => '/online/kursy/grammatika-gasuns-2026',
    'utm' => [
        'utm_source' => 'samskrte_schedule',
        'utm_medium' => 'onsite_cta',
        'utm_campaign' => $campaign,
        'utm_content' => 'g26_schedule',
        'utm_term' => 'beginner',
    ],
];

/*
 * up26 — волна вебинара «Традиции толкования упанишад» (вводное бесплатное
 * 05-10-2026 18:00 МСК): -c — запись на эфир, -p — страница курса с тарифами.
 * Пост канала несёт только /ga/up26-* под человеческой анкор-фразой (H6078).
 */
$up26Campaign = 'upanishady_webinar_oct_2026';
$up26Destinations = [
    'c' => '/webinar-upanishady-2026',
    'p' => '/k/tolkovaniia-upanisad-2-potok-2026',
];

foreach ($up26Destinations as $creative => $up26Destination) {
    $links["up26-ors-{$creative}"] = [
        'destination' => $up26Destination,
        'utm' => [
            'utm_source' => $channels['ors']['source'],
            'utm_medium' => $channels['ors']['medium'],
            'utm_campaign' => $up26Campaign,
            'utm_content' => "u26_{$creative}",
            'utm_term' => 'philosophy',
        ],
    ];
}

/*
 * Демо-кампания — постоянная смок-фикстура скилла anons-attribution-mint
 * (H5934): живые /ga/demo-* ключи, чтобы прогонять редирект + метки без
 * реального набора. Синтетические utm_source помечены demo_, поэтому
 * смок-клики не смешиваются с отчётной атрибуцией. Убирается, когда первая
 * реальная кампания этого скилла уедет в прод.
 */
$demoCampaign = 'attribution_smoke_demo';
$demoChannels = [
    'ors' => ['source' => 'demo_ors', 'medium' => 'owned_channel'],
    'mg' => ['source' => 'demo_mg', 'medium' => 'owned_channel'],
    'is' => ['source' => 'demo_is', 'medium' => 'paid_post'],
    'it' => ['source' => 'demo_it', 'medium' => 'partner_post'],
    'vk' => ['source' => 'demo_vk', 'medium' => 'broadcast'],
    'samskrte' => ['source' => 'demo_samskrte', 'medium' => 'crosslink'],
    'samskrtam' => ['source' => 'demo_samskrtam', 'medium' => 'crosslink'],
];

foreach ($demoChannels as $channel => $demoAttribution) {
    foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
        $links["demo-{$channel}-{$creative}"] = [
            'destination' => '/online',
            'utm' => [
                'utm_source' => $demoAttribution['source'],
                'utm_medium' => $demoAttribution['medium'],
                'utm_campaign' => $demoCampaign,
                'utm_content' => "demo_{$creative}",
                'utm_term' => 'smoke',
            ],
        ];
    }
}

return [
    'session_key' => 'tracked_link_attribution',
    'links' => $links,
    'story_campaigns' => [
        'm26' => [
            'destination' => '/online/kursy/grammatika-gasuns-2026',
            'utm_campaign' => $campaign,
            'utm_term' => 'beginner',
        ],
        'demo' => [
            'destination' => '/online',
            'utm_campaign' => $demoCampaign,
            'utm_term' => 'smoke',
        ],
        'lingq' => [
            'destination' => 'https://t.me/samskrtamru/4166',
            'utm_campaign' => 'linguistics_tasks_evergreen',
            'utm_term' => 'puzzle',
        ],
        'linga' => [
            'destination' => 'https://t.me/samskrtamru/4169',
            'utm_campaign' => 'linguistics_tasks_evergreen',
            'utm_term' => 'answer',
        ],
    ],
    'story_accounts' => [
        'mg' => 'telegram_marcisgasuns',
        'rs' => 'telegram_rusamskrtam',
    ],
];
