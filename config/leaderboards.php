<?php

declare(strict_types=1);

/**
 * Multi-board gamification leaderboards (H2052).
 *
 * Each board can be flipped off with `'enabled' => false` without code changes.
 * Periods (week / month / all) are always available; sources that only have
 * all-time data (Memrise import snapshot) repeat the same ranking for all tabs.
 */
return [

    'limit' => 10,

    /** FSRS rating → points for SRS boards (Again=1 … Easy=4). */
    'srs_rating_points' => [
        1 => 1, // Again
        2 => 2, // Hard
        3 => 3, // Good
        4 => 4, // Easy
    ],

    /** Points per authenticated /lila complete event. */
    'lila_complete_points' => 10,

    /**
     * Board registry. Order = UI order.
     * source: prana|srs|lila|memrise_import|combined
     * filter: optional deck slug prefix / drill / course_id
     */
    'boards' => [

        'prana' => [
            'enabled' => true,
            'label' => 'Прана (суммарно)',
            'hint' => 'Начисления праны за учёбу — общий счётчик',
            'source' => 'prana',
        ],

        'srs_all' => [
            'enabled' => true,
            'label' => 'SRS — все колоды',
            'hint' => 'Повторения карточек (Again…Easy → 1…4 очка)',
            'source' => 'srs',
            'filter' => null,
        ],

        'srs_memrise_all' => [
            'enabled' => true,
            'label' => 'SRS — все Memrise',
            'hint' => 'Все импортированные курсы Memrise',
            'source' => 'srs',
            'filter' => 'memrise-',
        ],

        'srs_memrise_6679375' => [
            'enabled' => true,
            'label' => 'SRS — Продлёнка',
            'hint' => 'Колода Memrise 6679375 «Продлёнка по санскриту»',
            'source' => 'srs',
            'filter' => 'memrise-6679375-',
        ],

        'srs_memrise_6502608' => [
            'enabled' => true,
            'label' => 'SRS — Memrise 6502608',
            'hint' => 'Импортированный курс Memrise 6502608',
            'source' => 'srs',
            'filter' => 'memrise-6502608-',
        ],

        'srs_memrise_6508023' => [
            'enabled' => true,
            'label' => 'SRS — Memrise 6508023',
            'hint' => 'Импортированный курс Memrise 6508023',
            'source' => 'srs',
            'filter' => 'memrise-6508023-',
        ],

        'srs_memrise_6517849' => [
            'enabled' => true,
            'label' => 'SRS — Memrise 6517849 (Bühler)',
            'hint' => 'Импортированный курс Memrise 6517849',
            'source' => 'srs',
            'filter' => 'memrise-6517849-',
        ],

        'srs_memrise_6522419' => [
            'enabled' => true,
            'label' => 'SRS — Memrise 6522419',
            'hint' => 'Импортированный курс Memrise 6522419',
            'source' => 'srs',
            'filter' => 'memrise-6522419-',
        ],

        'srs_anki_454628379' => [
            'enabled' => true,
            'label' => 'SRS — Anki Hindi Core',
            'hint' => 'Anki deck 454628379',
            'source' => 'srs',
            'filter' => 'anki-454628379',
        ],

        'lila_all' => [
            'enabled' => true,
            'label' => 'Игры /lila — все',
            'hint' => 'Завершённые раунды (только залогиненные)',
            'source' => 'lila',
            'filter' => null,
        ],

        'lila_sort' => [
            'enabled' => true,
            'label' => 'Игры — sort',
            'hint' => 'Тренажёр сортировки',
            'source' => 'lila',
            'filter' => 'sort',
        ],

        'lila_match' => [
            'enabled' => true,
            'label' => 'Игры — match',
            'hint' => 'Тренажёр сопоставления',
            'source' => 'lila',
            'filter' => 'match',
        ],

        'lila_cloze' => [
            'enabled' => true,
            'label' => 'Игры — cloze',
            'hint' => 'Тренажёр пропусков',
            'source' => 'lila',
            'filter' => 'cloze',
        ],

        'lila_table' => [
            'enabled' => true,
            'label' => 'Игры — table',
            'hint' => 'Тренажёр таблицы',
            'source' => 'lila',
            'filter' => 'table',
        ],

        'memrise_import_all' => [
            'enabled' => true,
            'label' => 'Memrise — перенесённые очки (все)',
            'hint' => 'Импорт лидерборда Memrise; привязка по memrise_username',
            'source' => 'memrise_import',
            'filter' => null,
        ],

        'memrise_import_6679375' => [
            'enabled' => true,
            'label' => 'Memrise — очки Продлёнки',
            'hint' => 'Перенесённый топ курса 6679375',
            'source' => 'memrise_import',
            'filter' => '6679375',
        ],

        'memrise_import_6502608' => [
            'enabled' => true,
            'label' => 'Memrise — очки 6502608',
            'source' => 'memrise_import',
            'filter' => '6502608',
        ],

        'memrise_import_6508023' => [
            'enabled' => true,
            'label' => 'Memrise — очки 6508023',
            'source' => 'memrise_import',
            'filter' => '6508023',
        ],

        'memrise_import_6517849' => [
            'enabled' => true,
            'label' => 'Memrise — очки 6517849',
            'source' => 'memrise_import',
            'filter' => '6517849',
        ],

        'memrise_import_6522419' => [
            'enabled' => true,
            'label' => 'Memrise — очки 6522419',
            'source' => 'memrise_import',
            'filter' => '6522419',
        ],

        'combined' => [
            'enabled' => true,
            'label' => 'Комбо — всё вместе',
            'hint' => 'Прана + SRS + lila + Memrise-import (одинаковые веса очков)',
            'source' => 'combined',
        ],
    ],
];
