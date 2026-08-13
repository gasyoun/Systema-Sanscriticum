<?php

declare(strict_types=1);

/**
 * CRM Wave 2 (H2484) — versioned lifecycle-rule contract.
 *
 * Windows live here (not in Blade / Filament) so dry-run and live send share
 * one denominator. Bumping a version is a new rule identity: already-sent
 * recipients of v1 stay deduplicated from v1, not from v2.
 */
return [
    'rules' => [
        'uncompleted_checkout' => [
            'version' => 1,
            'label' => 'Незавершённый чекаут',
            'min_age_hours' => (int) env('CRM_LIFECYCLE_CHECKOUT_MIN_HOURS', 2),
            'lookback_days' => (int) env('CRM_LIFECYCLE_CHECKOUT_LOOKBACK_DAYS', 14),
            'template_category' => 'reactivation',
            'subject' => 'Вы начали оформление — можно закончить',
            'body_html' => '<p>Вы начали оплату курса и не завершили её. Если это ещё актуально — вернитесь к оформлению из кабинета.</p>',
        ],
        'missing_first_cabinet_action' => [
            'version' => 1,
            'label' => 'Нет первого действия в кабинете',
            'grace_hours' => (int) env('CRM_LIFECYCLE_CABINET_GRACE_HOURS', 24),
            'lookback_days' => (int) env('CRM_LIFECYCLE_CABINET_LOOKBACK_DAYS', 14),
            'template_category' => 'reactivation',
            'subject' => 'Кабинет открыт — сделайте первый шаг',
            'body_html' => '<p>Доступ уже есть. Откройте кабинет и начните с первого урока — прогресс сохранится.</p>',
        ],
        'next_product_eligible' => [
            'version' => 1,
            'label' => 'Следующий курс',
            'lookback_days' => (int) env('CRM_LIFECYCLE_NEXT_PRODUCT_LOOKBACK_DAYS', 180),
            'template_category' => 'lead',
            'subject' => 'Открыт следующий курс программы',
            'body_html' => '<p>Вы уже учитесь на курсе, у которого есть продолжение. Следующий поток можно открыть, когда будете готовы.</p>',
        ],
    ],

    // Do not re-prepare / re-send the same rule+version to a user inside this window.
    'dedup_cooldown_days' => (int) env('CRM_LIFECYCLE_DEDUP_DAYS', 21),

    'follow_up_due_days' => (int) env('CRM_LIFECYCLE_FOLLOW_UP_DUE_DAYS', 2),
];
