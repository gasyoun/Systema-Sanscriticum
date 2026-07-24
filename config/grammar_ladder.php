<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MAIN grammar ladder for hybrid «Прогресс» (R29.6 / H1573)
|--------------------------------------------------------------------------
| One trajectory only (v1): письмо → грамматика I → грамматика II → тексты.
| Courses are resolved by title LIKE pattern (same discipline as
| config/trajectory.php / TrajectoryPaths) so cohort slugs can rotate.
| Full curriculum graph stays deferred (R29.6 scope).
*/

return [
    'stations' => [
        [
            'key' => 'letters',
            'title' => 'Письмо',
            'description' => 'Деванагари и чтение — станция входа.',
            'pattern' => env('LADDER_LETTERS_PATTERN', 'Деванагар'),
        ],
        [
            'key' => 'grammar_i',
            'title' => 'Грамматика I',
            'description' => 'Первая ступень грамматики.',
            'pattern' => env('LADDER_GRAMMAR_I_PATTERN', 'I ступ'),
        ],
        [
            'key' => 'grammar_ii',
            'title' => 'Грамматика II',
            'description' => 'Вторая ступень грамматики.',
            'pattern' => env('LADDER_GRAMMAR_II_PATTERN', 'II ступ'),
        ],
        [
            'key' => 'texts',
            'title' => 'Тексты',
            'description' => 'Чтение и рецитация первоисточников.',
            'pattern' => env('LADDER_TEXTS_PATTERN', 'Рецитаци'),
        ],
    ],
];
