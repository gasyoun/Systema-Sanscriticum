<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SRS (интервальные повторения) — H211, Wave 1
|--------------------------------------------------------------------------
|
| Фича за флагом. `enabled` ВКЛ по умолчанию (H1981 guest trial + shareable
| per-deck URLs shipped; product surface is ready). Явный `SRS_ENABLED=false`
| в `.env` выключает маршруты /dvaram/srs, /srs/{slug} и пункт меню.
| Числовые параметры — настройки планировщика FSRS и лимита новых карточек.
*/

return [
    // Главный рубильник. ON by default (product decision 30-07-2026):
    // public trial URLs + cabinet review are intended to be live; set
    // SRS_ENABLED=false only to dark the whole surface again.
    'enabled' => (bool) env('SRS_ENABLED', true),

    // Сколько новых (ещё не виденных) карточек показывать в день на колоду.
    'new_per_day' => (int) env('SRS_NEW_PER_DAY', 20),

    // Целевая удерживаемость FSRS (доля успешных вспоминаний). 0.9 — дефолт эталона.
    'desired_retention' => (float) env('SRS_DESIRED_RETENTION', 0.9),

    // Случайное «размытие» интервалов (антислипание карточек). В тестах отключается.
    'fuzz' => (bool) env('SRS_FUZZ', true),

    // Public /srs/{slug} guest trial: how many cards a non-registered visitor
    // may flip before the soft register wall. Progress is not persisted.
    'guest_trial_cards' => (int) env('SRS_GUEST_TRIAL_CARDS', 10),
];
