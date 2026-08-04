<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Временные загрузки Livewire (потолок для ВСЕХ Filament-полей)
    |--------------------------------------------------------------------------
    |
    | Файл появился ради одной строки — `rules`. Без него действует вендорный
    | дефолт `['required','file','max:12288']` (12 МБ), и он валидирует загрузку
    | РАНЬШЕ, чем Filament проверит свой `maxSize()`. То есть любое поле с
    | maxSize выше 12 МБ обещало больше, чем принимало: файлы с правками у
    | проверяющего ДЗ (30 МБ), вложения урока (100 МБ), PDF лекции (50 МБ) —
    | все молча отваливались с невнятным «The file failed to upload».
    |
    | 102400 = максимальный maxSize по проекту (вложения урока в LessonResource).
    | Это ПОТОЛОК, а не разрешение: у каждого поля остаётся свой `maxSize()`,
    | который и даёт человеку внятное сообщение об ошибке.
    |
    | ВНИМАНИЕ, побочный эффект: поля БЕЗ явного `maxSize()` (импорт CSV в
    | ListUsers, картинки в AnnouncementResource / ShopCourseResource /
    | LandingPageResource) поднялись с неявных 12 МБ до 100 МБ. Проставить им
    | явные пороги — отдельная задача; см. H1345 в SubscriberMagnetResource.
    |
    | Как и у HOMEWORK_* в config/homework.php, значение env-backed: серверные
    | limits (`upload_max_filesize`, `post_max_size`, `client_max_body_size`)
    | живут на VPS и в репозитории не записаны, а менять их приходится вместе.
    | Держите LIVEWIRE_UPLOAD_MAX_KB ниже прод-значения post_max_size.
    |
    | Файл сознательно частичный: Livewire мержит его через mergeConfigFrom, а
    | тот делает shallow merge по ВЕРХНЕМУ уровню — наши top-level ключи
    | побеждают целиком, остальные приезжают из vendor. Поэтому блок
    | temporary_file_upload расписан ПОЛНОСТЬЮ (вложенные ключи не
    | домерживаются), а прочие вендорные настройки тут не дублируются, чтобы не
    | пиннить их при обновлении пакета.
    |
    */

    'temporary_file_upload' => [
        'disk' => null,        // Default: filesystems.default
        'rules' => ['required', 'file', 'max:'.(int) env('LIVEWIRE_UPLOAD_MAX_KB', 102400)],
        'directory' => null,   // Default: 'livewire-tmp'
        'middleware' => null,  // Default: 'throttle:60,1'
        'preview_mimes' => [   // Типы, для которых Livewire отдаёт превью по временной ссылке.
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5, // Минут до аннулирования незавершённой загрузки.
        'cleanup' => true,      // Подчищать временные файлы старше 24 часов.
    ],

];
