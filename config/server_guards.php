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
     * ни crontab www-data нет, и «пропажа» там ничего не значит.
     *
     * Дефолт — Linux **и** APP_ENV=production. Раньше было только Linux: GitHub
     * Actions (ubuntu) включал verify, cabinet:probe валил 6 тестов кучей
     * «managed-file отсутствует» / «crontab www-data пуст». Явный
     * SERVER_GUARDS_VERIFY=true/false перекрывает дефолт (filter_var, чтобы
     * строка "false" из .env не стала truthy).
     */
    'verify_enabled' => filter_var(
        env(
            'SERVER_GUARDS_VERIFY',
            PHP_OS_FAMILY === 'Linux' && env('APP_ENV') === 'production'
        ),
        FILTER_VALIDATE_BOOL
    ),

];
