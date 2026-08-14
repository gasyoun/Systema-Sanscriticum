<?php

declare(strict_types=1);

/**
 * Shop / sales-funnel analytics (H2378).
 *
 * First-party truth for paid denominators stays in OrderPaymentConversionService
 * (config/conversion.php). Metrika is a browser proxy for pre-auth steps where
 * activity_events cannot store guests (user_id NOT NULL).
 */
return [

    'metrika' => [
        // samskrte.ru counter from ORS-FAQ ya_analytics.SAMSKRTE / H2062 baseline.
        'shop_counter_id' => env('YANDEX_METRIKA_SHOP_ID', '106964341'),
        'enabled' => filter_var(env('YANDEX_METRIKA_SHOP_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Canonical funnel event names (ActivityEvent + Metrika reachGoal).
    | Dedup is intentional: operators read unique users, not raw page spam.
    */
    'funnel_events' => [
        'course_page_view' => [
            'name' => 'course_page_view',
            'dedup' => 'user+course_id per calendar day',
            'metrika_goal' => 'course_page_view',
            'surfaces' => ['shop course page /k/{slug}', 'legacy 301 /online/kursy/{slug}'],
        ],
        'begin_checkout' => [
            'name' => 'begin_checkout',
            'dedup' => 'user+tariff_id per calendar day',
            'metrika_goal' => 'begin_checkout',
            'surfaces' => ['/checkout/{tariff}'],
        ],
        'payment_success' => [
            'name' => 'payment_success',
            'dedup' => 'once per payment_id',
            'metrika_goal' => 'payment_success',
            'surfaces' => ['/payment/success', 'Payment status → paid/success'],
        ],
        'first_cabinet_action' => [
            'name' => 'first_cabinet_action',
            'dedup' => 'once per user ever',
            'metrika_goal' => null,
            'surfaces' => ['cabinet.home.view', 'lesson_open — first after any activity'],
        ],
        /*
         | H2762 isolated flagship tests. First-party home is storefront_events
         | (guests allowed). Metrika names match when shopReachGoal fires.
         */
        'card_impression' => [
            'name' => 'card_impression',
            'dedup' => 'visitor+course_id per calendar day',
            'metrika_goal' => 'card_impression',
            'surfaces' => ['/online Kochergina catalog card when CATALOG_NEXT_STEP'],
        ],
        'next_step_click' => [
            'name' => 'next_step_click',
            'dedup' => 'raw clicks',
            'metrika_goal' => 'next_step_click',
            'surfaces' => ['/online/next-step/{buhler|texts|recitation}'],
        ],
        'sample_play' => [
            'name' => 'sample_play',
            'dedup' => 'raw preview opens',
            'metrika_goal' => 'sample_play',
            'surfaces' => ['/k/{slug}/preview on the CTA A/B flagship'],
        ],
    ],
];
