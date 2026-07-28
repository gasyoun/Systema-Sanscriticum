<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Пульс кабинета — login + ключевые поверхности (H1777)
    |--------------------------------------------------------------------------
    |
    | Закрывает дыру, которую homepage-uptime и heartbeat:ping не видят:
    | «сайт отдаёт 200 на /», а /login, /dvaram или Filament лежат с 500 /
    | пустым Auth / Whoops. Раз в cron планировщик логинит smoke-менеджера
    | (TEST_MANAGER_*) in-process и дёргает несколько GET-поверхностей.
    |
    | Fail-open: без TEST_MANAGER_PASSWORD команда no-op и SUCCESS; любая
    | сетевая ошибка healthchecks не роняет слот планировщика. Тревога
    | — через CABINET_PROBE_PING_URL (healthchecks.io /fail при поломке).
    |
    */

    // Пусто = не пинговать внешний сторож (сам probe всё равно логирует).
    'ping_url' => (string) env('CABINET_PROBE_PING_URL', ''),

    // Частота. 15 мин: тяжелее, чем heartbeat:ping (рендер Blade/Filament),
    // но достаточно, чтобы «кабинет лежит» не ждал GitHub-крона часами.
    'cron' => (string) env('CABINET_PROBE_CRON', '*/15 * * * *'),

    'timeout' => (int) env('CABINET_PROBE_TIMEOUT', 15),

    // Маркеры, по которым страница считается «упавшей», даже при HTTP 200.
    'error_markers' => [
        'Whoops',
        'Server Error',
        'Fatal error',
        'Stack trace',
        'TokenMismatchException',
        'SQLSTATE[',
    ],

    /*
    | Маршруты, которые обязаны отвечать 2xx после успешного login.
    | name — Laravel route name; uri — запасной путь.
    | panel=true: Filament (/admin) — менеджер must canAccessPanel.
    |
    | НЕ включаем /library|/progress|/access: они 404, пока
    | features.cabinet_hybrid = OFF (hybrid chassis, H1481). Hybrid-поверхности
    | добавляются динамически в cabinet:probe, когда флаг включён.
    */
    'surfaces' => [
        ['name' => 'student.dashboard', 'label' => 'cabinet /dvaram'],
        ['name' => 'student.messages', 'label' => 'cabinet /messages'],
        ['name' => 'student.calendar', 'label' => 'cabinet /calendar'],
        ['name' => 'student.open-lessons', 'label' => 'cabinet /open-lessons'],
        ['uri' => '/admin', 'label' => 'filament /admin', 'panel' => true],
    ],

    // Добавляются к surfaces, только если features.cabinet_hybrid = true.
    'hybrid_surfaces' => [
        ['name' => 'student.library', 'label' => 'cabinet /library (hybrid)'],
        ['name' => 'student.progress', 'label' => 'cabinet /progress (hybrid)'],
        ['name' => 'student.access', 'label' => 'cabinet /access (hybrid)'],
    ],

];

