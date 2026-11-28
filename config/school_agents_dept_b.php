<?php

declare(strict_types=1);

/*
 * Школа-агенты, департамент B «Продажи и онбординг» — фаза-1 по ростеру v2.
 * (H6101, эпик E026; канон имён/ID — ростер v1 grill 04-10-2026, волны —
 * ростер v2 05-10-2026 + диспозиция 92ID.)
 *
 * ЭТО РЕЕСТР ФАЗИ-1, НЕ ЗАПУСК. 0 прод-письма до гейта волны (H6084
 * инвариант): статусы в этом файле только 'reserved' / 'child'.
 *
 * Роли 13, 19, 20 — первая волна W1 — реализуются детьми-хендоффами
 * H6184 / H6186 / H6185 (пакет Hermes 06-10); зонтик принимает их
 * evidence и второй вариант НЕ пишет (разделение scope).
 *
 * Гейты E026, закодированные ниже (решения 05-10):
 *  Q3 — suggester: агент готовит — человек отправляет; первые 30 дней всё
 *       через human-send (единая точка SupportHumanSendService, H6183).
 *  Q5 — pii-guard фаза 0: маскинг ПДн до любого LLM-вызова; без обёртки
 *       роль не запускается (152-ФЗ; обезличка — SupportStudentPiiMasker).
 *  Q6 — скидки/рассрочки: полный запрет автономии; только предлагать,
 *       решает MG.
 *  Q7 — токены: killgate-слой H5867 + лимит по роли (token_budget_daily);
 *       «без новых трат» — подписочная квота z.ai. Финальные числа —
 *       настраиваемые дефолты, меняет MG без правки инвариантов.
 *  Q9 — исходящие через tone-гайд + редколлегию скиллов; обязательный
 *       litredaktor-пасс отменён.
 *  Q8 — golden-верификация учебного контента: на департамент B напрямую
 *       не падает (это роли D/J), у faq-rag действует сущ. drift-гейт
 *       корпуса FAQ (H6917).
 *
 * Роли фазы-1 входят в общую квоту 25 (Q2; ростер v2, блок B+C+D): W1 —
 * 13/19/20 (child-owned), остальные семь ролей B (14-18, 21, 22) — W2,
 * где W2 = 18 B/C-ролей по карте поверхностей. Маршрутизация «13|59»
 * решена как общий слой H6190 БЕЗ канонического role ID (evidence H6190:
 * слой не создаёт седьмого ID) => один слот квоты свободен; слот
 * добирается W3 (D-роли учебки) по визе MG на гейте волны, не седьмой
 * W1-ролью (календарь измерений важнее темпа, решения 05-10) — см.
 * docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_B_SALES_ONBOARDING_PHASE1_H6101_2026-11-18.md.
 */

return [

    'department' => 'B',
    'name' => 'Продажи и онбординг',
    'handoff' => 'H6101',
    'epic' => 'E026',
    'phase1_quota' => 25,

    // Q3: первые 30 дней любой исходящий — human-send (готовит-человек-шлёт).
    'human_send_period_days' => 30,

    // Ничего из этого файла не пишет в прод до гейта волны.
    'prod_write_enabled' => false,

    'roles' => [

        [
            'id' => 13,
            'slug' => 'first-contact-247',
            'label' => 'first-contact-247: мгновенный первый ответ в TG 24/7, квалификация пятью вопросами',
            'type' => 'llm',
            'wave' => 'W1',
            'status' => 'child',
            'implementation' => 'child',
            'child_handoff' => 'H6184',
            'token_budget_daily' => 150000,
            'gates' => [
                'human_send_required' => true,      // Q3
                'pii_guard_required' => true,       // Q5
                'discount_autonomy_allowed' => false, // Q6
                'tone_guide_required' => true,      // Q9
            ],
            'surfaces' => [
                'channel' => '@samskrte_bot',
                'pii_masker' => 'App\Services\Support\SupportStudentPiiMasker',
                'human_send' => 'App\Services\Support\HumanSend\SupportHumanSendService',
                'routing' => 'H6190 — общий слой маршрутизации W1 (не дублировать)',
                'escalation' => 'docs/RUNBOOK_SUPPORT_ESCALATION.md',
            ],
        ],

        [
            'id' => 14,
            'slug' => 'consult-seller',
            'label' => 'consult-seller: консультация — подбор потока, возражения, запись на триал',
            'type' => 'llm',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 150000,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'leads' => 'App\Filament\Resources\LeadResource (lead_signup)',
                'consult_practice' => 'consult-практики школы',
            ],
        ],

        [
            'id' => 15,
            'slug' => 'trial-scheduler',
            'label' => 'trial-scheduler: запись/перенос/напоминания вводного занятия',
            'type' => 'deterministic',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 0,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'trial_schedule' => 'trial_schedule + H5864 readiness',
            ],
        ],

        [
            'id' => 16,
            'slug' => 'no-show-rescuer',
            'label' => 'no-show-rescuer: после пропуска триала — перезапись + ссылка на запись эфира',
            'type' => 'hybrid',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 50000,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'recordings' => 'записи эфиров в кабинете',
                'trial_schedule' => 'trial_schedule (пропуск триала)',
            ],
        ],

        [
            'id' => 17,
            'slug' => 'payment-concierge',
            'label' => 'payment-concierge: сопровождение чекаута Tochka — не прошёл, повтор, другой способ',
            'type' => 'deterministic',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 0,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'checkout' => 'config/checkout.php, Tochka probes',
                'money_guard' => 'никаких мутаций платежей; только чтение статуса и ссылка на штатный путь',
            ],
        ],

        [
            'id' => 18,
            'slug' => 'dunning-agent',
            'label' => 'dunning-agent: напоминания рассрочки/неоплаты — вежливо, без стыда, по стоп-правилам',
            'type' => 'deterministic',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 0,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false, // Q6: только напоминаем, никто не «прощает» и не пересчитывает
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'receivables' => 'App\Models\MutualSettlement / receivables (только чтение)',
            ],
        ],

        [
            'id' => 19,
            'slug' => 'onboarding-greeter',
            'label' => 'onboarding-greeter: первая неделя — приветствие, чек-лист, привязка TG',
            'type' => 'deterministic',
            'wave' => 'W1',
            'status' => 'child',
            'implementation' => 'child',
            'child_handoff' => 'H6186',
            'token_budget_daily' => 0,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'channel' => '@samskrtamru_bot /telegram/connect',
                'contact_binding' => 'App\Services\Support\EnrollContactBinder',
            ],
        ],

        [
            'id' => 20,
            'slug' => 'faq-rag',
            'label' => 'faq-rag: RAG по оферте/расписанию/отзывам — ответы без человека',
            'type' => 'llm',
            'wave' => 'W1',
            'status' => 'child',
            'implementation' => 'child',
            'child_handoff' => 'H6185',
            'token_budget_daily' => 150000,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'faq' => 'App\Services\Support\Faq (ранкер корпуса FAQ)',
                'drift_gate' => 'H6917 faq-corpus-drift (golden-слой W1 H6181)',
            ],
        ],

        [
            'id' => 21,
            'slug' => 'upsell-path',
            'label' => 'upsell-path: следующая ступень после потока, персональные предложения',
            'type' => 'llm',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 150000,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false, // Q6: предложить — да, ставить цену/скидку — нет
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'custdev' => 'custdev upsell puller',
            ],
        ],

        [
            'id' => 22,
            'slug' => 'objection-log',
            'label' => 'objection-log: журнал возражений → еженедельная сводка MG — что чинить в продукте',
            'type' => 'llm',
            'wave' => 'W2',
            'status' => 'reserved',
            'implementation' => 'umbrella',
            'child_handoff' => null,
            'token_budget_daily' => 150000,
            'gates' => [
                'human_send_required' => true,
                'pii_guard_required' => true,
                'discount_autonomy_allowed' => false,
                'tone_guide_required' => true,
            ],
            'surfaces' => [
                'summary' => 'еженедельная сводка MG (черновик, human-send)',
            ],
        ],

    ],

];
