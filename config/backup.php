<?php

use App\Notifications\BackupNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => env('APP_NAME', 'laravel-backup'),

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 */
                'include' => [
                    // H364: расширено с DB-only на файловое хранилище — загрузки,
                    // финансовые шаблоны, импорты, лекции (storage/app/*).
                    storage_path('app'),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('app/livewire-tmp'),
                    storage_path('app/telegram-harvest/pilot'),
                    // Сам каталог назначения бэкапов и временный каталог Zip:
                    // без исключения каждый архив заворачивал внутрь себя все
                    // предыдущие (рекурсивный рост: 22-08-2026 архив 1.87 ГБ,
                    // внутри — прошлонедельные 1.4 ГБ) и раздувался каждую
                    // неделю. См. также split_upload ниже.
                    storage_path('app/Laravel'),
                    storage_path('app/backup-temp'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => true,
                'relative_path' => null,
            ],

            /*
             * The names of the connections to the databases that should be backed up
             */
            'databases' => [
                'mysql',
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            // Только local: spatie льёт архив одним WebDAV-PUT, а Yandex режет
            // PUT >1 ГБ по HTTP 413 (история: обрезки по 11 МиБ, потом отказ).
            // Off-site нога переехала в SplitUploadToYandex (событие
            // BackupWasSuccessful): архив делится на части <1 ГБ и льётся
            // по частям. Аудит свежести yandex_disk живёт в
            // ShellSystemInspector::readBackupDestinations — он добавляет диск
            // из split_upload.disk к этому списку, поэтому guards продолжают
            // мерить off-site, хотя spatie туда больше не пишет напрямую.
            'disks' => [
                'local',
            ],
        ],

        // Off-site через части: Яндекс не переваривает большие тела — 413 на
        // >1 ГБ, а крупные тела ведут себя непредсказуемо: 22–23-08-2026 сотни
        // МБ «сохранялись» 2xx без записи (чёрная дыра, три «успеха» на ~2 ГБ,
        // части 404), 24-08-2026 strace показал TLS-stall (sendto EAGAIN, байты
        // не уходят минутами) на 50 МиБ частях. Класс ≤~20 МиБ доезжает честно:
        // 11.7 / 18.6 МиБ зипы легли целиком во все дни наблюдений. Восстановление:
        // скачать все части одной группы, склеить `cat *.part-*-of-*.zip >
        // backup.zip`, распаковать.
        'split_upload' => [
            // Куда льём части (должен совпадать с диском из destination.disks,
            // который НЕ local).
            'disk' => 'yandex_disk',
            // Размер части. 20 МиБ = класс, переживающий Яндекс 22–24-08-2026
            // (решение MG 24-08-2026: срез с 50). Порог guards
            // BACKUP_MIN_ARCHIVE_MB в scripts/server_guards.conf стоит ниже
            // суммы полной группы; одинокая часть полным архивом не считается
            // (SplitGroupMath).
            'max_part_mb' => (int) env('BACKUP_SPLIT_PART_MB', 20),
            // Ретеншн частей на off-site: зеркалит keep_daily_backups_for_days.
            'keep_parts_days' => (int) env('BACKUP_KEEP_PARTS_DAYS', 16),
            // Свежепроцессная верификация каждой части (backup:verify-yandex-part):
            // Яндекс изредка отвечал 2xx ничего не сохранив — верим только
            // отдельному процессу со свежим curl-хендлом.
            'verify' => (bool) env('BACKUP_VERIFY_PARTS', true),
        ],

        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],

        'notifiable' => BackupNotifiable::class,

        'mail' => [
            // H3312: реального получателя решает App\Notifications\BackupNotifiable
            // через config('services.admin.email') (env ADMIN_EMAIL), fail-closed:
            // пусто -> отправка skip с warning, без краша. Само поле 'to' spatie
            // валидирует filter_var и бросает InvalidConfig на пустой/битой
            // строке (краш парсинга конфига = crash loop бэкапов), поэтому здесь
            // стоит синтаксически валидный плейсхолдер, который НЕ используется
            // как адресат. Не менять на пустую строку!
            'to' => 'backup-notifications-unset@example.com',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'robot@tvoy-sayt.ru'),
                'name' => 'Академия Санскрита (Авто-Бэкап)',
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            // Бэкап еженедельный (Kernel::schedule, понедельник) — 8 дней запаса
            // против «понедельник ещё не наступил» false-positive в health-check.
            'disks' => ['local', 'yandex_disk'],
            // H1345: порог был 5000 МБ при том, что cleanup ниже режет старые
            // бэкапы уже на 1000 МБ — значит health-check не мог сработать
            // НИКОГДА (мёртвая тревога). Теперь он стоит чуть выше потолка
            // уборки, и потому означает осмысленное: «уборка не справляется».
            'health_checks' => [
                MaximumAgeInDays::class => (int) env('BACKUP_MAX_AGE_DAYS', 8),
                MaximumStorageInMegabytes::class => (int) env('BACKUP_MAX_STORAGE_MB', 1200),
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,

            // Наш лимит в 1000 МБ. Держать НИЖЕ BACKUP_MAX_STORAGE_MB выше:
            // уборка — механизм, health-check — тревога о его отказе.
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_CLEANUP_MB', 1000),
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];
