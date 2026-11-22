<?php

declare(strict_types=1);

/*
 | Департамент C «Учебные операции» ростера 92 — реестр фазы-1 (H6103, E026).
 |
 | Канон имён/ID — Uprava docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md
 | (§C, ID 23–34); волны — docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md;
 | карта read-only поверхностей и правила слоя —
 | docs/SCHOOL_AGENTS_W2_READONLY_SURFACE_2026-10-05.md (§3, §4.8: послолбцовая
 | PII-верификация и построчная проводка гейтов Q3/Q5/Q6/Q7 — задача этого
 | департамента при запуске каждой роли; этот реестр — её стартовая точка).
 |
 | Все роли департамента C — жители W2 (квота фазы-1 Q2 = 25: W1 7 + W2 18).
 | Роль 23 `schedule-keeper` — житель W1, реализована ребёнком H6187 и здесь
 | НЕ дублируется (правило зонтика: принимать evidence детей, не писать второй
 | вариант). Гейт запуска W2 = «2 недели без Sev-инцидентов на W1» — ещё не
 | снят, поэтому ВСЕ роли департамента строятся в тени: фича-флаги по
 | умолчанию OFF, ноль прод-записей, исходящие — только suggester Q3 через
 | human-send H6183.
 |
 | Гейты на каждую строку (ростер V2 §«Гейты автономии»):
 |  - q3_suggester: агент готовит черновик, отправляет человек;
 |  - q5_pii: персональные данные не покидают контур — детерминированные роли
 |    не делают НИ ОДНОГО LLM-вызова (маскинг не нужен — текст никому не
 |    передаётся); при появлении LLM-ветки — обёртка SupportStudentPiiMasker
 |    фазы 0 ДО вызова, fail-closed;
 |  - q6_no_discount_autonomy: черновики не предлагают скидки/рассрочки —
 |    только человек решает (запрет автономии, решение MG);
 |  - q7_tokens: deterministic ⇒ ноль токенов подписочной квоты
 |    (killgate-слой H5867 и машина квот H6174 остаются общими слоями);
 |    LLM-роли (33) — только через квотную машину H6174, allowances —
 |    измеренные значения;
 |  - q9_tone: школьный тон, без канцелярита и стыда; редколлегия скиллов —
 |    обязательный litredaktor-пасс ОТМЕНЁН (решение 05-10).
 |
 | Статусы строк: implemented — сервис + тесты в этом репо; pending — роль
 | объявлена реестром, реализация — следующий юнит запуска (после снятия
 | гейта волны или по решению MG). Роли 8/39/42 (политика) в департаменте
 | отсутствуют и новыми ID реестр не пополняется.
 */

return [
    /*
     | Регистр ролей департамента C. Ключ — канонический ID ростера v1;
     | изменять состав — PR в Systema-Sanscriticum со ссылкой на карту
     | поверхностей W2 (молчаливый дрейф запрещён, правило §4.7).
     */
    'roles' => [
        23 => [
            'name' => 'schedule-keeper',
            'wave' => 'W1',
            'engine' => 'deterministic',
            'status' => 'implemented',
            'implemented_by' => 'H6187',
            'feature_flag' => 'hermes_schedule_keeper',
            'service' => \App\Services\Support\ScheduleKeeper\ScheduleKeeperService::class,
            'readonly_tables' => ['schedules', 'groups'],
        ],
        24 => [
            'name' => 'teacher-dispatcher',
            'wave' => 'W2',
            'engine' => 'llm',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['teachers', 'course_teacher', 'schedules', 'groups', 'vacation_quorum_polls'],
            'notes' => 'K1: контакты преподавателей маскировать до LLM; замены — только предложение человеку',
        ],
        25 => [
            'name' => 'attendance-tracker',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['lesson_user', 'lesson_views', 'activity_events', 'schedules', 'groups'],
            'notes' => 'K2: агрегаты по группам, красные флаги — в дайджест куратору',
        ],
        26 => [
            'name' => 'room-steward',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['lesson_access_grants', 'course_access_windows', 'membership_access_verdicts', 'schedules', 'zapisi_class_schedules'],
            'notes' => 'K2: доступ по оплате — читать вердикты, НИКОГДА не выдавать/не отзывать',
        ],
        27 => [
            'name' => 'deadline-butler',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'implemented',
            'implemented_by' => 'H6103',
            'feature_flag' => 'hermes_deadline_butler',
            'service' => \App\Services\Support\Hermes\LearningOps\DeadlineButlerService::class,
            'readonly_tables' => ['lessons', 'lesson_user', 'homework_submissions', 'groups'],
        ],
        28 => [
            'name' => 'progress-tracker',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'implemented',
            'implemented_by' => 'H6103',
            'feature_flag' => 'hermes_progress_tracker',
            'service' => \App\Services\Support\Hermes\LearningOps\ProgressTrackerService::class,
            'readonly_tables' => ['lesson_user', 'lesson_views', 'lessons', 'groups'],
        ],
        29 => [
            'name' => 'homework-dispatcher',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['lessons', 'course_material_submissions', 'course_materials', 'lesson_user'],
            'notes' => 'K4: контент студента — маскинг Q5 до любого LLM; «кто молчит» — флаги куратору',
        ],
        30 => [
            'name' => 'certificate-issuer',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['certificates', 'certificate_milestones', 'courses', 'course_group'],
            'notes' => 'K2; LLM не требуется (карта W2 §3); выпуск — подтверждение человека',
        ],
        31 => [
            'name' => 'cohort-registrar',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['course_waitlist_items', 'waitlist_entries', 'waitlist_votes', 'waitlist_outreaches', 'course_interest_requests', 'groups', 'course_group'],
            'notes' => 'K2; аллокация групп — только предложение человеку',
        ],
        32 => [
            'name' => 'make-up-agent',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['schedules', 'lesson_user', 'groups', 'zapisi_class_schedules'],
            'notes' => 'K2; подтверждения слотов отработки — human-send Q3',
        ],
        33 => [
            'name' => 'classroom-supply',
            'wave' => 'W2',
            'engine' => 'llm',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['lessons', 'course_blocks', 'course_materials', 'teaching_glossary', 'dictionaries'],
            'notes' => 'K0+K1; конспект к уроку за 24 ч; LLM — только через квотную машину H6174',
        ],
        34 => [
            'name' => 'exam-session-agent',
            'wave' => 'W2',
            'engine' => 'deterministic',
            'status' => 'pending',
            'feature_flag' => null,
            'readonly_tables' => ['exam_scores', 'lessons', 'course_group'],
            'notes' => 'K2; без авто-вердиктов о списывании (правило ростера v1 §D34)',
        ],
    ],

    /*
     | Общие гейты департамента (применяются к КАЖДОЙ строке roles; при
     | запуске роли провождаются построчно — карта W2 §4.8).
     */
    'gates' => [
        'q2_quota' => [
            'phase1_total' => 25,
            'dept_w1_roles' => [23],
            'dept_w2_roles' => [24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34],
            'note' => 'квота заполняется 7+18=25 при маршрутизации W1=59 (карта W2 §1); запуск W2 — после гейта «2 недели без Sev на W1»',
        ],
        'q3_suggester' => true,
        'q5_pii_phase0' => true,
        'q6_no_discount_autonomy' => true,
        'q7_tokens' => [
            'deterministic_zero_llm' => true,
            'quota_machine' => \App\Services\Support\Hermes\Quota\SchoolRoleQuotaService::class,
            'killgate_layer' => 'H5867',
        ],
        'q9_tone' => 'school-tone-гайд; обязательный litredaktor-пасс отменён (решение 05-10)',
    ],
];
