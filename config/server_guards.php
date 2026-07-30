<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ресурсные предохранители прода (H1914)
    |--------------------------------------------------------------------------
    |
    | Значения предохранителей здесь НЕ живут — они в scripts/server_guards.conf,
    | который читают и `guards:verify`, и scripts/server_guards_apply.sh. Здесь
    | только путь к ним и выключатель проверки.
    |
    */

    'spec_path' => env('SERVER_GUARDS_SPEC', base_path('scripts/server_guards.conf')),

    'template_root' => env('SERVER_GUARDS_TEMPLATES', base_path('scripts/server_guards')),

    /*
     * Проверка осмысленна только на прод-хосте: на dev-боксе и в CI ни systemd,
     * ни crontab www-data нет, и «пропажа» там ничего не значит. По умолчанию —
     * только Linux и не тестовое окружение; на прод-контуре это true, в тестах
     * ничего не шевелится. Разрешительный дефолт для прода намеренно НЕ равен
     * `false` — пересобранный хост без явной переменной окружения должен
     * проверять предохранители, а не молча переставать это делать (H1914).
     */
    'verify_enabled' => env('SERVER_GUARDS_VERIFY', PHP_OS_FAMILY === 'Linux' && env('APP_ENV') !== 'testing'),

];
