<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Guard pack .92 (H4194): RAM/swap/load + queue-lag + compromise-integrity
    |--------------------------------------------------------------------------
    |
    | Три watchdog-команды, каждая — отдельная строка cron через
    | systema-watchdog-run.sh (docs/GUARD_PACK_INSTALL.md), а не внутри
    | schedule:run: L1/L8 (OOM/livelock) и C1-C3 (rogue admin/webroot dropper)
    | по определению могут случиться, когда сам планировщик висит, и сторож не
    | должен делить его судьбу (docs/server-resource-guards.md §3).
    |
    | Пороги calibration-заглушки — не измерены на живом .92, ставить их надо
    | вместе с первым прогоном `--dry` на проде (H4194 acceptance).
    */

    // --- guards:resources ---------------------------------------------------
    // Своп > доли RAM — CRITICAL (L1: /tmp скретч съел своп до почти полного).
    'swap_used_ratio_critical' => (float) env('GUARD_PACK_SWAP_RATIO_CRITICAL', 0.25),
    // load1 > N × ядер — CRITICAL (L8: load average 370 при 8 ядрах).
    'load1_per_core_critical' => (float) env('GUARD_PACK_LOAD1_PER_CORE_CRITICAL', 2.0),
    // Доступной памяти меньше этой доли от total — CRITICAL.
    'mem_available_ratio_critical' => (float) env('GUARD_PACK_MEM_AVAILABLE_RATIO_CRITICAL', 0.15),

    // --- guards:queue-lag -----------------------------------------------------
    // Самое старое ожидающее задание старше этого числа минут — CRITICAL
    // (N2: остановившийся queue:work не шлёт писем, все HTTP-проверки зелёные).
    'queue_oldest_job_max_minutes' => (int) env('GUARD_PACK_QUEUE_OLDEST_MAX_MINUTES', 30),

    // --- guards:compromise-integrity -----------------------------------------
    // Рост числа admin/super_admin сверх baseline — CRITICAL (C1: 15+1 rogue
    // admins прошли мимо HTTP-200 мониторов). Baseline пишется первым прогоном.
    'admin_baseline_path' => storage_path('app/server_guards/admin_baseline.json'),

    // Инвентарь .php в вебруте — новый файл сверх baseline — CRITICAL (C2/C3:
    // дропперы и бэкдор galex_patch.php). Baseline НЕ обновляется самой пробой
    // (--write-baseline — отдельный осознанный шаг человека/деплоя).
    'webroot_php_baseline_path' => storage_path('app/server_guards/webroot_php_baseline.json'),
    'webroot_scan_dir' => public_path(),

    // Общий тумблер уведомлений (Filament DB notification админам, как
    // storage:check/CheckReceivablesThreshold) — выключить в CI/--dry не нужно,
    // команды сами уважают --dry.
    'notify_roles' => ['super_admin', 'admin'],
];
