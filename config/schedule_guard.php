<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Внешний потолок одного захода планировщика
    |--------------------------------------------------------------------------
    |
    | Столько секунд живёт `php artisan schedule:run` под обёрткой
    | scripts/server_guards/sbin/systema-schedule-run.sh, прежде чем её `timeout`
    | снимет заход; переживший это straggler добивается reaper'ом на следующем
    | проходе, то есть не позже 2x этого значения.
    |
    | АВТОРИТЕТНЫЙ ИСТОЧНИК ЧИСЛА — scripts/server_guards.conf (SCHEDULE_MAX_SECONDS).
    | Здесь копия для расчёта TTL замков планировщика в app/Console/Kernel.php:
    | читать .conf в schedule() значило бы дисковый разбор каждую минуту и падение
    | всего планировщика на кривом файле. Расхождение копии с оригиналом ловит
    | тест MadelineSyncGuardsTest::test_external_ceiling_config_matches_server_guards_conf,
    | а расхождение конфига с ЖИВОЙ машиной — `php artisan guards:verify`.
    |
    */

    'max_seconds' => (int) env('SYSTEMA_SCHEDULE_MAX_SECONDS', 900),

];
