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
            // MG 18-09-2026: flip null -> goal (activation after payment counts
            // in the same counter; browser marker gated by the pre-emit check).
            'metrika_goal' => 'first_cabinet_action',
            'surfaces' => ['cabinet.home.view', 'lesson_open — first after any activity'],
        ],
        /*
         | Кабинетные цели Tier 1 (MG 18-09-2026, план
         | docs/METRIKA_GOALS_SHOP_CABINET_2026-09-18.md). Тот же счётчик
         | 106964341, но init с webvisor:false + clickmap:false (152-ФЗ:
         | ни одной записи сессий залогиненных). Имена целей = имя события §4
         | с точками → подчёркивания; ids 639422183–639422234 созданы в
         | Метрике через management API (PR #2700). Механика доставки:
         | клиентский мост (telemetry partial, METRIKA_BRIDGE) либо
         | data-metrika-goal маркеры, которые рендерит сервер.
         */
        'cabinet_home_view' => [
            'name' => 'cabinet_home_view',
            'dedup' => 'raw renders (activity truth: cabinet.home.view)',
            'metrika_goal' => 'cabinet_home_view',
            'surfaces' => ['cabinet dashboard render — data-metrika-goal marker'],
        ],
        'lesson_open' => [
            'name' => 'lesson_open',
            'dedup' => 'raw lesson renders (activity truth: lesson_open, dedup in TrackLessonViewJob)',
            'metrika_goal' => 'lesson_open',
            'surfaces' => ['lesson page render — data-metrika-goal marker'],
        ],
        'lesson_complete' => [
            'name' => 'lesson_complete',
            'dedup' => 'once per lesson per user',
            // NO server emitter exists yet (§4 spec) — goal reads 0 until the
            // writer ships; kept here so the registry stays the single map.
            'metrika_goal' => 'lesson_complete',
            'surfaces' => ['server emitter NOT shipped yet'],
        ],
        'lesson_mark_mastered' => [
            'name' => 'lesson_mark_mastered',
            'dedup' => 'only on NEW completion (server gates the flash)',
            'metrika_goal' => 'lesson_mark_mastered',
            'surfaces' => ['completeLesson flash metrika_goal → marker on redirect target'],
        ],
        'library_shelf_view' => [
            'name' => 'library_shelf_view',
            'dedup' => 'raw impressions (activity truth: library.shelf.view)',
            'metrika_goal' => 'library_shelf_view',
            'surfaces' => ['«Записи» shelves — client telemetry bridge (data-track-impression)'],
        ],
        'path_station_view' => [
            'name' => 'path_station_view',
            'dedup' => 'raw impressions (activity truth: path.station.view)',
            'metrika_goal' => 'path_station_view',
            'surfaces' => ['лестница чтения — client telemetry bridge (data-track-impression)'],
        ],
        'access_renewal_start' => [
            'name' => 'access_renewal_start',
            'dedup' => 'raw clicks (activity truth: access.renewal.start)',
            'metrika_goal' => 'access_renewal_start',
            'surfaces' => ['кнопки продления кабинета — client telemetry bridge (data-track-event)'],
        ],
        'access_renewal_complete' => [
            'name' => 'access_renewal_complete',
            'dedup' => 'once per payment (server: PaymentTelemetryObserver)',
            'metrika_goal' => 'access_renewal_complete',
            'surfaces' => ['/payment/success when confirmed && payment.is_self_service'],
        ],
        'zoom_join_click' => [
            'name' => 'zoom_join_click',
            'dedup' => 'raw clicks (activity truth: schedule_join_clicks)',
            'metrika_goal' => 'zoom_join_click',
            'surfaces' => ['кнопки «На занятие» (class.join) — client telemetry bridge (data-track-event)'],
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
