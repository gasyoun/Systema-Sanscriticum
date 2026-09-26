<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | H5445 (P3, E017) — ежедневная оперативная сверка
    |--------------------------------------------------------------------------
    |
    | Плановый запуск: money:reconcile-daily --persist --scheduled (04:35,
    | за флагом features.money_daily_reconciliation). Контракт:
    | docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md.
    |
    */

    // Dead-man heartbeat (Better Stack / healthchecks): пинг «ok» после
    // успешной детерминированной сверки, /fail при дрейфе, сломанном тождестве
    // или запрещённой записи. Пусто = громкий warning not_supported в логе.
    'ping_url' => env('MONEY_RECON_PING_URL', ''),

    // Каталог выписок зачислений банка (для ручного запуска импортёра).
    // Сам источник bank_statement данные берёт из money_bank_statement*,
    // а не из каталога: день present только если импортированная выписка
    // покрывает его целиком (H5480).
    'bank_statement_dir' => env('MONEY_RECON_BANK_STATEMENT_DIR', ''),

    // H5480: допуск на лаг расчёта банка. Расчёты дня D сопоставляются с
    // оплатами канала bank_acquiring за [D − lag, D] (T+1 по умолчанию).
    'settlement_lag_days' => (int) env('MONEY_RECON_SETTLEMENT_LAG_DAYS', 1),

    // Потолок комиссии эквайринга в базисных пунктах (300 = 3%): банк
    // зачисляет агрегат УЖЕ за вычетом комиссии, поэтому расчёт ниже оплат —
    // норма, а ниже коридора — исключение currency_amount_mismatch.
    'acquiring_fee_max_bps' => (int) env('MONEY_RECON_ACQUIRING_FEE_MAX_BPS', 300),

    // Абсолютный допуск на округления, копейки.
    'aggregate_tolerance_kopecks' => (int) env('MONEY_RECON_AGGREGATE_TOLERANCE_KOPECKS', 100),

    // Таблица пакетов выплат P2 (H5444). Пока её нет — контрольные суммы
    // выплат берутся из легаси teacher_payouts (статус источника legacy).
    'payout_packages_table' => env('MONEY_RECON_PAYOUT_PACKAGES_TABLE', 'teacher_payout_packages'),
];
