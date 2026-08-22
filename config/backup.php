<?php

use Spatie\Backup\Notifications\Notifiable;
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

        // Off-site через части: Yandex Disk не принимает файлы больше ~1 ГБ
        // ни по WebDAV, ни по простому REST-upload — единственный честный путь
        // для архива любого размера. Восстановление: скачать все части одной
        // группы, склеить `cat *.part-*-of-*.zip > backup.zip`, распаковать.
        'split_upload' => [
            // Куда льём части (должен совпадать с диском из destination.disks,
            // который НЕ local).
            'disk' => 'yandex_disk',
            // Потолок одной части с запасом под лимит Яндекса (~1 ГБ).
            'max_part_mb' => (int) env('BACKUP_SPLIT_PART_MB', 700),
            // Ретеншн частей на off-site: зеркалит keep_daily_backups_for_days.
            'keep_parts_days' => (int) env('BACKUP_KEEP_PARTS_DAYS', 16),
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

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => 'pe4kin.85@mail.ru',

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
