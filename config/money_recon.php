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

    // Каталог, откуда бухгалтер кладёт выгруженные выписки Точки (для удобства
    // команды money:import-statement-credits; путь к файлу можно задать и явно).
    'bank_statement_dir' => env('MONEY_RECON_BANK_STATEMENT_DIR', ''),

    /*
    |--------------------------------------------------------------------------
    | H5480 — дневной агрегатный контроль выписки зачислений
    |--------------------------------------------------------------------------
    |
    | Построчного сопоставления нет и быть не может: выписка не несёт
    | идентификатора студента (H4645). Сверяются суммы дня.
    |
    */

    // Лаг расчёта: зачисление дня D сопоставляется с оплатами окна [D−лаг; D].
    'settlement_lag_days' => (int) env('MONEY_RECON_SETTLEMENT_LAG_DAYS', 1),

    // Допуск агрегатного сравнения QR-расчётов, копейки (0 = точное равенство).
    'aggregate_tolerance_kopecks' => (int) env('MONEY_RECON_AGGREGATE_TOLERANCE_KOPECKS', 0),

    // Эквайринг карт приходит НЕТТО. Правдоподобная комиссия — [0; max] б.п.;
    // выход за границы (в том числе отрицательная комиссия) = исключение.
    'acquiring_max_fee_bps' => (int) env('MONEY_RECON_ACQUIRING_MAX_FEE_BPS', 350),

    // Таблица пакетов выплат P2 (H5444). Пока её нет — контрольные суммы
    // выплат берутся из легаси teacher_payouts (статус источника legacy).
    'payout_packages_table' => env('MONEY_RECON_PAYOUT_PACKAGES_TABLE', 'teacher_payout_packages'),
];
