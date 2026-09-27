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

    // Каталог выписок зачислений банка. Пока импорта зачислений нет (парсеры
    // H4200 читают только расходы), источник bank_statement всегда missing, и
    // прогон честно incomplete.
    'bank_statement_dir' => env('MONEY_RECON_BANK_STATEMENT_DIR', ''),

    // Таблица пакетов выплат P2 (H5444). Пока её нет — контрольные суммы
    // выплат берутся из легаси teacher_payouts (статус источника legacy).
    'payout_packages_table' => env('MONEY_RECON_PAYOUT_PACKAGES_TABLE', 'teacher_payout_packages'),
];
