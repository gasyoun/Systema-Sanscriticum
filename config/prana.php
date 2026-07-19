<?php

return [
    // Курс: сколько праны равно одному рублю скидки. 10 праны = 1 ₽.
    'rate' => 10,

    // Максимальная доля от итоговой цены (после промокода и лояльности),
    // которую можно покрыть праной.
    'max_share_of_price' => 0.30,

    // P2P-перевод праны между студентами (тратимый balance; ранг/lifetime не растёт).
    'daily_p2p_limit' => (int) env('PRANA_DAILY_P2P_LIMIT', 30),          // всего в день
    'daily_p2p_per_user_limit' => (int) env('PRANA_DAILY_P2P_PER_USER', 10), // одному получателю в день

    // Сгорание (decay) тратимого баланса за бездействие. По умолчанию ВЫКЛЮЧЕНО —
    // включается осознанно, т.к. списывает прану у студентов.
    'decay' => [
        'enabled' => (bool) env('PRANA_DECAY_ENABLED', false),
        'inactive_days' => (int) env('PRANA_DECAY_INACTIVE_DAYS', 30), // неактивен N+ дней
        'percent' => (int) env('PRANA_DECAY_PERCENT', 10),             // сжечь % баланса за прогон
    ],

    // Сколько начислять за разные виды активности.
    // NB: ключа 'referral' здесь больше нет — награда за реферала переведена на
    // денежный кредит (config('referral.credit_amount'), см. ReferralService).
    // Метка 'referral' в 'reasons' ниже СОХРАНЕНА: ею подписываются исторические
    // prana_transactions со старой прана-наградой (PranaTransaction::reasonLabel).
    'rewards' => [
        'lesson_complete' => 10,
        'course_complete' => 500,
        'open_lesson_view' => 20,
        'daily_login' => 5,
        'payment_success' => 50,

        // H447 — Saraswati trainer suite Phase 1. Awarded on EVERY graded SRS
        // review, right or wrong (deliberate — copied from Saraswati 1.3.8's
        // "3 either way", anxiety-sensitive audience, see
        // Uprava/docs/SARASWATI_TRAINERS_2026.md §1). Idempotent via
        // PranaService's (user_id, reason, source_type, source_id) unique key,
        // keyed off the srs_review_logs row.
        'srs_review' => 3,
    ],

    // Ранги по накопленной пране (lifetime_prana, тратами не уменьшается).
    // Геймификация поверх скидочной праны: ранг — статус, а не деньги.
    // Должны идти по возрастанию min. Порог первого ранга = 0.
    'ranks' => [
        ['key' => 'sishya', 'name' => 'Śiṣya · ученик', 'min' => 0],
        ['key' => 'adhyayin', 'name' => 'Adhyāyin · изучающий', 'min' => 200],
        ['key' => 'snataka', 'name' => 'Snātaka · окончивший ступень', 'min' => 1000],
        ['key' => 'acarya', 'name' => 'Ācārya · наставник', 'min' => 3000],
        ['key' => 'pandita', 'name' => 'Paṇḍita · ученый', 'min' => 8000],
    ],

    // Человекочитаемые подписи для истории и «как заработать».
    'reasons' => [
        'lesson_complete' => 'Завершен урок',
        'course_complete' => 'Курс пройден полностью',
        'open_lesson_view' => 'Просмотр открытого урока',
        'daily_login' => 'Ежедневный вход',
        'payment_success' => 'Покупка курса',
        'referral' => 'Приглашенный друг оплатил курс',
        'spent_on_purchase' => 'Списано при оплате',
        'spent_on_perk' => 'Покупка в магазине праны',
        'refund_failed' => 'Возврат за несостоявшуюся оплату',
        'admin_grant' => 'Начислено администратором',
        'admin_deduct' => 'Списано администратором',
        'p2p_sent' => 'Подарок другому студенту',
        'p2p_received' => 'Подарок от студента',
        'decay' => 'Сгорело за бездействие',
        'streak' => 'Бонус за серию дней',
        'srs_review' => 'Повторение карточки',
    ],

    // Бонус праны за серию активных дней подряд (streak). Веха → бонус. Начисляется
    // один раз при достижении вехи; на новой серии после сброса — снова.
    'streak_bonuses' => [
        7 => 50,
        30 => 200,
        100 => 1000,
    ],
];
