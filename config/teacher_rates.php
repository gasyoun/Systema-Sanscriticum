<?php

// Generated from Uprava data/teacher_rate_timelines.json @ sha256:584b7e8211fe
// by tools/gen_teacher_rates_config.py (H3531 mine -> H3532/S2 artifact). DO NOT HAND-EDIT.
// source_chat: Отдел финансов | Рабочая группа · miner: tools/mine_finansy_chat.py · handoff: H3531
//
// Канон «на руки»: (Σ поступлений периода ₽ × bank_slice%) × ставка(t) − прямые вычеты ± перерасчёты;
// для EUR-получателей конвертация по курсу на дату выплаты; НПД −6 % — отдельная пометка шага выплаты.
// Слайс банка применяется ТОЛЬКО когда период несёт bank_slice_pct (эра до окт-2025 — без среза).

return [
    'meta' => [
        'source_hash' => '584b7e8211fe',
        'generated_at' => '2026-08-25T19:58:23Z',
        'handoff' => 'H3532',
    ],

    'canon' => [
        'formula' => '(поступления ₽ × 92%) × ставка(t)',
        'bank_slice_pct_reference' => 92.0,
        'fx_eur_rub_fallback' => 90.1127, // исторический курс (реестр); живой курс — finance_snapshots type fx_eur_rub
    ],

    'recipients' => [
        'leytan' => [
            'name' => 'Лейтан Эдгар',
            'aliases' => ['Лейтан', 'Эдгар'],
            'channel' => 'paypal_mg', // A1/A7: «€ по курсу дня», PayPal MG
            'lane' => 'EUR',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 65.0, 'bank_slice_pct' => null, 'from' => '2024-12-23', 'to' => '2025-08-21'],
                ['kind' => 'percent', 'value_pct' => 60.0, 'bank_slice_pct' => null, 'from' => '2025-10-19', 'to' => '2026-01-23'],
                ['kind' => 'percent', 'value_pct' => 60.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-16', 'to' => null],
            ],
            'direct_deductions' => [
                ['who' => 'Новикову', 'amount_rub' => 1400.0, 'from' => '2024-12-23', 'to' => '2025-07-19'],
                ['who' => 'Новикову', 'amount_rub' => 1680.0, 'from' => '2025-07-20', 'to' => '2025-12-07'],
                ['who' => 'Новикову', 'amount_rub' => 1920.0, 'from' => '2025-12-08', 'to' => null],
            ],
            'block_price_obs' => [['price_rub' => 4000.0, 'seen_at' => '2024-12-23'], ['price_rub' => 4800.0, 'seen_at' => '2026-01-23']], // наблюдения цен блоков из расчётов
        ],
        'trefilova' => [
            'name' => 'Трефилова Елена',
            'aliases' => ['Трефилова'],
            'channel' => 'tochka_ip_gasuns', // A6 paid_note: «лист июнь… ИП Гасунс»
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-07-14', 'to' => '2026-01-21'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-12', 'to' => null],
            ],
            'direct_deductions' => [
            ],
            'block_price_obs' => [['price_rub' => 6000.0, 'seen_at' => '2026-01-21']], // наблюдения цен блоков из расчётов
        ],
        'kostina' => [
            'name' => 'Костина Екатерина',
            'aliases' => ['Костина'],
            'channel' => 'paypal_mg', // A2 paid_note: «286 евро PayPal»
            'lane' => 'EUR',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-10-19', 'to' => '2026-01-11'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-14', 'to' => null],
            ],
            'direct_deductions' => [
            ],
            'block_price_obs' => [['price_rub' => 6000.0, 'seen_at' => '2026-01-11']], // наблюдения цен блоков из расчётов
        ],
        'usha' => [
            'name' => 'Уша Санка',
            'aliases' => ['Уша'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-07-14', 'to' => '2026-02-08'],
                ['kind' => 'percent', 'value_pct' => 20.0, 'bank_slice_pct' => 92.0, 'from' => '2026-03-25', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'tolchelnikov' => [
            'name' => 'Толчельников Иван',
            'aliases' => ['Толчельников'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-10-19', 'to' => '2026-01-25'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-18', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'druzhinin' => [
            'name' => 'Дружинин Владимир',
            'aliases' => ['Дружинин'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 75.0, 'bank_slice_pct' => null, 'from' => '2024-12-22', 'to' => '2025-12-24'],
                ['kind' => 'percent', 'value_pct' => 75.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-26', 'to' => null],
            ],
            'direct_deductions' => [
            ],
            'block_price_obs' => [['price_rub' => 4000.0, 'seen_at' => '2025-11-20']], // наблюдения цен блоков из расчётов
        ],
        'litvinenko' => [
            'name' => 'Литвиненко Ольга',
            'aliases' => ['Литвиненко'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-07-16', 'to' => '2025-11-15'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-15', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'voroshilov' => [
            'name' => 'Ворошилов Максим',
            'aliases' => ['Ворошилов'],
            'channel' => 'tochka_maria', // A3 paid_note: лист март, оплата Марии
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-11-04', 'to' => '2026-01-29'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-03-22', 'to' => null],
                ['kind' => 'fixed_monthly', 'value_rub' => 25000.0, 'from' => '2026-03-23', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'leonov' => [
            'name' => 'Леонов Максим',
            'aliases' => ['Леонов'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-10-21', 'to' => '2025-11-15'],
                ['kind' => 'fixed_monthly', 'value_rub' => 15000.0, 'from' => '2025-11-14', 'to' => '2026-01-22'],
                ['kind' => 'fixed_monthly', 'value_rub' => 20000.0, 'from' => '2026-04-20', 'to' => '2026-05-21'],
            ],
            'direct_deductions' => [
            ],
        ],
        'klebanov' => [
            'name' => 'Клебанов Андрей',
            'aliases' => ['Клебанов'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-10-22', 'to' => '2026-02-03'],
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-02-24', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'leonchenko' => [
            'name' => 'Леонченко',
            'aliases' => ['Леонченко'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => null, 'from' => '2025-10-21', 'to' => '2026-01-29'],
            ],
            'direct_deductions' => [
            ],
        ],
        'gornostaeva' => [
            'name' => 'Горностаева Оксана',
            'aliases' => ['Горностаева'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 10.0, 'bank_slice_pct' => 92.0, 'from' => '2026-03-25', 'to' => null],
                ['kind' => 'fixed_monthly', 'value_rub' => 10000.0, 'from' => '2025-07-22', 'to' => '2025-08-22'],
            ],
            'direct_deductions' => [
            ],
        ],
        'shcherbak' => [
            'name' => 'Щербак Сергей',
            'aliases' => ['Щербак'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-03-22', 'to' => '2026-03-25'],
                ['kind' => 'fixed_monthly', 'value_rub' => 24000.0, 'from' => '2026-03-25', 'to' => null],
            ],
            'direct_deductions' => [
            ],
        ],
        'pakhomov' => [
            'name' => 'Пахомов Сергей',
            'aliases' => ['Пахомов'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'percent', 'value_pct' => 30.0, 'bank_slice_pct' => 92.0, 'from' => '2026-05-01', 'to' => null],
                ['kind' => 'fixed_monthly', 'value_rub' => 34838.0, 'from' => '2026-05-01', 'to' => '2026-06-01'],
            ],
            'direct_deductions' => [
            ],
        ],
        'emelyanov' => [
            'name' => 'Емельянов Владимир',
            'aliases' => ['Емельянов'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'fixed_monthly', 'value_rub' => 25000.0, 'from' => '2026-04-20', 'to' => '2026-05-21'],
            ],
            'direct_deductions' => [
            ],
        ],
        'paribok' => [
            'name' => 'Парибок',
            'aliases' => ['Парибок'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'fixed_monthly', 'value_rub' => 500000.0, 'from' => '2025-03-28', 'to' => '2025-05-03'],
                ['kind' => 'fixed_monthly', 'value_rub' => 200000.0, 'from' => '2025-11-14', 'to' => '2025-12-15'],
            ],
            'direct_deductions' => [
            ],
        ],
        'lundysheva' => [
            'name' => 'Лундышева Ольга',
            'aliases' => ['Лундышева'],
            'channel' => 'tochka_maria', // дефолт без прямого доказательства канала в чате ⚠️
            'lane' => 'RUB',
            'rate_periods' => [
                ['kind' => 'fixed_monthly', 'value_rub' => 20000.0, 'from' => '2026-04-20', 'to' => '2026-05-21'],
            ],
            'direct_deductions' => [
            ],
        ],
    ],

    'staff' => [
        // Штат (не преподаватели LMS): суммы из брифа H3532 / реестра ставок, ритм — по платёжным записям Марии.
        'ilyushina' => [
            // Ильюшина М. М. = Поликарпова с 31-03-2026 (alias обязателен, PLAN §6).
            // ~30 000 ₽/мес ритмом 18+12: записи Марии 3448(18 000 @10-13-е) + 3485/3563(12 000 @24-29-е); шлёт сама с ИП (Точка).
            'name' => 'Ильюшина М. М. (Поликарпова)',
            'aliases' => ['Ильюшина', 'Поликарпова', 'Мария'],
            'channel' => 'self_ip',
            'lane' => 'RUB',
            'tranches' => [
                ['day' => 12, 'amount_rub' => 18000.0],
                ['day' => 26, 'amount_rub' => 12000.0],
            ],
            'monthly_rub' => 30000.0,
        ],
        'gorbachenko' => [
            // Горбаченко (поддержка, 🍎): 29 325 ₽/мес — бриф H3532.
            'name' => 'Горбаченко',
            'aliases' => ['Горбаченко'],
            'channel' => 'tochka_maria',
            'lane' => 'RUB',
            'monthly_rub' => 29325.0,
            'pay_day' => 5,
        ],
        'kravchenko' => [
            // Кравченко Иван: база 17 030 ₽/мес (якорь A4, записи 09.02/10.03) + премии отдельными строками
            // (10 650, 10 000, 5 320, 17 021 — записи Марии фев–апр 2026); фондовые 28 000 ₽ — контекст, не трата.
            'name' => 'Кравченко Иван',
            'aliases' => ['Кравченко', 'Иван'],
            'channel' => 'tochka_ip_gasuns',
            'lane' => 'RUB',
            'monthly_rub' => 17030.0,
            'pay_day' => 9,
            'premiums_observed_rub' => [10650.0, 10000.0, 5320.0, 17021.0],
        ],
    ],

    'contractors' => [
        'kholovchenko' => [
            // Подрядчик Холовченко: €200–300/мес — бриф H3532 (в safe_withdrawal встречается написание «Головченко»).
            'name' => 'Холовченко',
            'aliases' => ['Холовченко', 'Головченко'],
            'channel' => 'paypal_mg',
            'lane' => 'EUR',
            'fee_eur_min' => 200.0,
            'fee_eur_max' => 300.0,
            'pay_day' => 15,
        ],
    ],

    // НПД −6 % самозанятых — отдельный шаг выплаты (зачёт до выплаты, ruling 27-07),
    // НЕ внутри брутто-расчёта (ARCHITECTURE контракт №3). Пометка в панели, гейт бэктеста — брутто.
    'npd' => [
        'kostina' => ['pct' => 6.0],
        'usha' => ['pct' => 6.0],
    ],
];
