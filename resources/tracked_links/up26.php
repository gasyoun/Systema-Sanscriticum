<?php

declare(strict_types=1);

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
