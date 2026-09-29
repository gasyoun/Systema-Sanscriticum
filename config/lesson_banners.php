<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Плашки занятий (обложки записей)
    |--------------------------------------------------------------------------
    |
    | Плашка = фон из PSD курса + дата и номер занятия, дорисованные кодом.
    | Потребитель — n8n-воркфлоу ZOOM 1.4: после записи он ищет в папке группы
    | на Google Диске файл «ГГГГ-ММ-ДД.jpg» и ставит его обложкой. Laravel
    | только рисует и отдаёт список; на Диск кладёт отдельный n8n-воркфлоу
    | «Плашки занятий» (Google-креденшл и таблица папок уже живут там).
    |
    | Включение — features.lesson_banners (default OFF).
    |
    */

    // На сколько дней вперёд рисовать плашки (от «сейчас»).
    'lead_days' => (int) env('LESSON_BANNERS_LEAD_DAYS', 7),

    // Готовые JPEG — на публичном диске: n8n забирает их по прямой ссылке,
    // это обложки записей, а не персональные данные.
    'image_disk' => env('LESSON_BANNERS_IMAGE_DISK', 'public'),
    'image_dir' => 'lesson-banners',

    // Фон шаблона (PNG без слоёв даты/номера) — на публичном диске (превью в
    // админке), PSD-исходник — на приватном, как в «Дизайне курсов».
    'template_disk' => env('LESSON_BANNERS_TEMPLATE_DISK', 'public'),
    'template_dir' => 'lesson-banner-templates',
    'psd_disk' => env('LESSON_BANNERS_PSD_DISK', 'local'),
    'psd_dir' => 'lesson-banner-psd',

    // Качество JPEG (0–100).
    'jpeg_quality' => (int) env('LESSON_BANNERS_JPEG_QUALITY', 90),

    // Каталог шрифтов (TTF/OTF), на которые spec шаблона ссылается по имени
    // файла. ВНЕ git: шрифты плашек коммерческие (Charter ITC, Fedra Sans), а
    // репозиторий публичный. Загружаются через «Плашки занятий» → «Новый
    // шаблон» и живут в storage/app, который деплой не трогает.
    // Шрифта нет → рисуем запасным (DejaVu Sans из dompdf, кириллица есть) и
    // пишем предупреждение: плашка без даты хуже плашки не тем шрифтом.
    'fonts_dir' => env('LESSON_BANNERS_FONTS_DIR', storage_path('app/lesson-banner-fonts')),
    'max_font_kb' => (int) env('LESSON_BANNERS_MAX_FONT_KB', 10240),
    'fallback_font' => base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),

    // Часовой пояс ТЕКСТА даты на плашке. Имя файла считается в UTC — так его
    // ищет ZOOM 1.4 (start_time.split('T')[0] из вебхука Zoom).
    'display_timezone' => 'Europe/Moscow',

    // Статусы групп, для которых рисуем.
    'group_statuses' => ['forming', 'active'],

    // Лимиты загрузки шаблона (КБ). max_psd_kb обязан быть ниже
    // LIVEWIRE_UPLOAD_MAX_KB — тот же инвариант, что в config/design_assets.php.
    'max_background_kb' => (int) env('LESSON_BANNERS_MAX_BACKGROUND_KB', 20480),
    'max_psd_kb' => (int) env('LESSON_BANNERS_MAX_PSD_KB', 98304),
];
