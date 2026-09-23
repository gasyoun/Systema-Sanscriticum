<?php

return [
    // Human-confirmed 30-day measurement window: 23 Sep–22 Oct 2026, Moscow time.
    // This starts observation only; paid support still requires a separately
    // verified future Schedule and its staffing snapshot below.
    'observation_started_on' => '2026-09-23',

    // Published free grammar webinar verified against production on 19-09-2026.
    // Excerpt verified against public captions on 22-09-2026: script vs language.
    'preview_lesson_id' => 1464,
    'preview_youtube_id' => 'FmdnLXZ4UFo',
    'preview_start_seconds' => 5337,
    'preview_end_seconds' => 5440,

    'day2_message_unstaffed' => 'День 2. Как устроено санскритское слово 🧩'."\n\n"
        .'Короткое задание по корню и аффиксу. Дата следующей групповой консультации пока не подтверждена; можно оставить вопрос, но бесплатный формат не обещает личного ответа.'."\n\n"
        .'{link}',

    // Bind staffing approval to ONE schedule row. Never roll approval forward
    // automatically when MARATHON_SCHEDULE_ID changes.
    'staffed_schedule_id' => null,
    'staffing_confirmed_at' => null,
    'staffed_schedule_start' => null,
    'staffed_schedule_end' => null,
];
