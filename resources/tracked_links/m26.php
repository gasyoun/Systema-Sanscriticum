<?php

declare(strict_types=1);

/*
 * m26 — кампания «Грамматика Гасунса 2026»: 7 каналов × 5 креативов
 * (s/v/c/t/h) + отдельный ключ расписания. Кампания же несёт story-формат
 * m26 (см. $storyCampaigns ниже).
 */

$campaign = 'grammar_gasuns_autumn_2026';

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

$storyCampaigns['m26'] = [
    'destination' => '/online/kursy/grammatika-gasuns-2026',
    'utm_campaign' => $campaign,
    'utm_term' => 'beginner',
];
