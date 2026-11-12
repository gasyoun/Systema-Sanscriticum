<?php

declare(strict_types=1);

/*
 * bf11 — ЧП-промо «шесть записей» (H8045, пост @samskrte 11-11-2026, скидка
 * −50% 11-11→30-11-2026): обзорная подборка из шести записей курсов. Креатив =
 * курс подборки (ством совпадает промокод NOV50-*), p — закрывающий CTA на
 * каталог /online. Канал ors — основной пост; samskrtam — зеркальный репост
 * @samskrtamru: стандарт не разрешает переиспользовать ors-ключи на другой
 * площадке, источник обязан различать переходы (UTM-стандарт 12-09-2026).
 */

$bf11Campaign = 'black_friday_recordings_nov_2026';
$bf11Destinations = [
    'zach' => '/k/blagopriyatnyye-zachiny-yogicheskoy-traditsii-2025',
    'astr' => '/k/astronomiia-dlia-astrologov-2-potok-osen-2025',
    'mol' => '/k/ezednevnye-molitvy-s-usei-sankoi-1-e-v-zapisi',
    'han' => '/k/razbor-xanuman-calisy-2026',
    'cal' => '/k/vostocnye-kalendari-2026',
    'ved' => '/k/likbez-po-vedante-2025',
    'p' => '/online',
];

foreach (['ors', 'samskrtam'] as $bf11Channel) {
    foreach ($bf11Destinations as $bf11Creative => $bf11Destination) {
        $links["bf11-{$bf11Channel}-{$bf11Creative}"] = [
            'destination' => $bf11Destination,
            'utm' => [
                'utm_source' => $channels[$bf11Channel]['source'],
                'utm_medium' => $channels[$bf11Channel]['medium'],
                'utm_campaign' => $bf11Campaign,
                'utm_content' => "bf11_{$bf11Creative}",
                'utm_term' => 'recordings',
            ],
        ];
    }
}
