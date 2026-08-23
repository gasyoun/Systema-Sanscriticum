<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | «Сколько можно взять себе» (safe withdrawal, практика «Нескучных финансов»)
    |--------------------------------------------------------------------------
    | Read-only расчёт доступной к выводу суммы для владельца ИП на УСН 6 %:
    | балансы − обязательства горизонта − налоговый резерв − операционный резерв.
    | Все правки параметров — через .env, без кода. Каждая строка расчёта на
    | экране подписана формулой; допущения помечены «предварительно».
    */

    // Горизонт обязательств в днях (НФ: 60 дней стандарт).
    'horizon_days' => (int) env('SAFE_WITHDRAWAL_HORIZON_DAYS', 60),

    // Операционный резерв в месяцах среднемесячных расходов (НФ: 1–3 мес).
    'op_reserve_months' => (float) env('SAFE_WITHDRAWAL_OP_RESERVE_MONTHS', 1),

    // Получатели персонала, которые АКТИВНЫ даже если в LMS молчат ≥2 мес
    // (платят себе/получают мимо LMS). Список имён через запятую, совпадение
    // по подстроке.
    'staff_active_names' => collect(explode(',', (string) env('SAFE_WITHDRAWAL_STAFF_ACTIVE', 'Ильюшина')))
        ->map(fn ($s) => trim($s))
        ->filter()
        ->all(),

    // Постоянный штат с оплатой мимо LMS (источник — ручной реестр Марии,
    // скрапится ежемесячно). Перекрывает LMS-ставку при совпадении подстроки
    // и добавляет тех, кого в LMS нет вовсе. Цифры — по реестру за июль 2026;
    // править здесь или через SAFE_WITHDRAWAL_STAFF_OVERRIDES (JSON).
    'staff_overrides' => json_decode(
        (string) env('SAFE_WITHDRAWAL_STAFF_OVERRIDES',
            json_encode([
                ['match' => 'Ильюшина', 'monthly' => 30000.0],
                ['match' => 'Кравченко', 'monthly' => 26511.93],
                ['match' => 'Кузнецова', 'monthly' => 60000.0],
                ['match' => 'Головченко', 'monthly' => 26010.0],
            ], JSON_UNESCAPED_UNICODE)
        ) ?: '[]',
        true
    ),

    // УСН «доходы», ставка налога.
    'usn_rate' => (float) env('SAFE_WITHDRAWAL_USN_RATE', 0.06),

    // НДФЛ налогового агента (зарплата единственного сотрудника).
    'ndfl_rate' => (float) env('SAFE_WITHDRAWAL_NDFL_RATE', 0.13),

    // Страховые взносы за сотрудницу: общий тариф и МСП-льгота (15% свыше МРОТ).
    'insurance_general_rate' => (float) env('SAFE_WITHDRAWAL_INSURANCE_RATE', 0.30),
    'msp_supper_rate' => (float) env('SAFE_WITHDRAWAL_MSP_SUPPER_RATE', 0.15),
    'mrot_monthly' => (float) env('SAFE_WITHDRAWAL_MROT_MONTHLY', 27093.00),

    // Взносы ИП за себя: фиксированная годовая сумма + 1% с дохода сверх порога.
    'ip_fixed_yearly' => (float) env('SAFE_WITHDRAWAL_IP_FIXED_YEARLY', 57390.00),
    'ip_extra_rate' => (float) env('SAFE_WITHDRAWAL_IP_EXTRA_RATE', 0.01),
    'ip_extra_threshold' => (float) env('SAFE_WITHDRAWAL_IP_EXTRA_THRESHOLD', 300000.00),

    // Среднемесячные прочие расходы (opex) вне зарплатного контура. Если задано
    // в .env — используется как есть (ручной реестр Марии полнее данных LMS);
    // иначе — среднее «Расхода» за 6 месяцев минус получатели зарплатного контура,
    // с плашкой «предварительно» (реестр показывает больше).
    'opex_monthly_override' => env('SAFE_WITHDRAWAL_OPEX_MONTHLY'),
];
