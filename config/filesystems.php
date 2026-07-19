<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // S3-совместимое объектное хранилище (H1345). Диск ОДИН — провайдер
        // выбирается переменными окружения, потому что и VK Cloud, и Yandex
        // Object Storage, и Selectel говорят на одном протоколе S3; заводить
        // отдельный диск на каждого значило бы копировать одну и ту же секцию.
        //
        // Готовые наборы (полные значения — в .env.example и в
        // docs/ROADMAP_MEDIA_STORAGE_2026_2028.md §5):
        //   VK Cloud  : AWS_ENDPOINT=https://hb.ru-msk.vkcloud-storage.ru
        //               AWS_DEFAULT_REGION=ru-msk
        //   Yandex    : AWS_ENDPOINT=https://storage.yandexcloud.net
        //               AWS_DEFAULT_REGION=ru-central1
        // Обоим нужен AWS_USE_PATH_STYLE_ENDPOINT=true.
        //
        // ВАЖНО: bucket должен быть ЗАКРЫТЫМ. Публичный bucket превращает
        // приватные работы студентов в «секретный URL» — доступ по ссылке без
        // проверки прав. Отдача — только через temporaryUrl() с подписью.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        // Off-site назначение еженедельного бэкапа — WebDAV поверх Яндекс.Диска
        // (driver зарегистрирован в AppServiceProvider::boot() через Storage::extend).
        // YANDEX_DISK_APP_PASSWORD — пароль приложения (НЕ основной пароль аккаунта),
        // создаётся на https://id.yandex.ru/security/app-passwords (тип «Диск/WebDAV»).
        'yandex_disk' => [
            'driver' => 'webdav',
            'baseUri' => env('YANDEX_DISK_WEBDAV_URL', 'https://webdav.yandex.ru'),
            'username' => env('YANDEX_DISK_LOGIN'),
            'password' => env('YANDEX_DISK_APP_PASSWORD'),
            'prefix' => env('YANDEX_DISK_BACKUP_PATH', '/Backups/systema-sanscriticum'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
