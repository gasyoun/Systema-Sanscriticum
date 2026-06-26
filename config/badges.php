<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Каталог бейджей (достижений)
    |--------------------------------------------------------------------------
    | Бейджи ВЫЧИСЛЯЮТСЯ из уже существующих сигналов (без отдельного хранилища):
    | каждый бейдж = метрика + порог. App\Support\Badges считает метрики юзера и
    | сравнивает. Метрики: lessons_completed, courses_completed, referrals,
    | lifetime_prana, gifts_given.
    */
    'catalog' => [
        ['key' => 'first_lesson',  'name' => 'Первый шаг',        'icon' => '🌱', 'desc' => 'Пройден первый урок',              'metric' => 'lessons_completed', 'threshold' => 1],
        ['key' => 'diligent',      'name' => 'Усердие',           'icon' => '📚', 'desc' => 'Пройдено 10 уроков',               'metric' => 'lessons_completed', 'threshold' => 10],
        ['key' => 'scholar',       'name' => 'Эрудит',            'icon' => '🎓', 'desc' => 'Пройдено 50 уроков',               'metric' => 'lessons_completed', 'threshold' => 50],
        ['key' => 'graduate',      'name' => 'Выпускник',         'icon' => '🏅', 'desc' => 'Курс пройден полностью',           'metric' => 'courses_completed', 'threshold' => 1],
        ['key' => 'mentor',        'name' => 'Наставник',         'icon' => '🤝', 'desc' => 'Приглашённый друг оплатил курс',   'metric' => 'referrals',         'threshold' => 1],
        ['key' => 'ambassador',    'name' => 'Амбассадор',        'icon' => '🌟', 'desc' => 'Трое приглашённых оплатили курс',  'metric' => 'referrals',         'threshold' => 3],
        ['key' => 'prana_keeper',  'name' => 'Хранитель праны',   'icon' => '🪷', 'desc' => 'Накоплено 1000 праны',             'metric' => 'lifetime_prana',    'threshold' => 1000],
        ['key' => 'generous',      'name' => 'Щедрый',            'icon' => '🎁', 'desc' => 'Подарил прану другому студенту',   'metric' => 'gifts_given',       'threshold' => 1],
    ],
];
