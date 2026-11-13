<?php

declare(strict_types=1);

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

$storyCampaigns['demo'] = [
    'destination' => '/online',
    'utm_campaign' => $demoCampaign,
    'utm_term' => 'smoke',
];
