<?php

declare(strict_types=1);

/*
 * H6546: лейн «видео-анонс» — видео от белого списка в учебном чате
 * автоматически копируется (Bot API copyMessage от @zapisi_ORSbot) во все
 * остальные чаты того же курса + админам уходит черновик.
 *
 * Решения грилла MG 12-10-2026 (docs/DECISIONS_TELEGRAM_VIDEO_ANONS_2026-10-12.md,
 * исполнение — Uprava H6546): авто-пост разрешён ТОЛЬКО для учебных чатов из
 * карты ниже; публичные поверхности (канал «Оповещения санскритян») в скоуп
 * не входят; сторис по заголовку MG — отдельный флаг, по умолчанию off.
 */

return [
    // Рубильник лейна целиком (kill switch без деплоя).
    'enabled' => (bool) env('VIDEO_ANONS_ENABLED', true),

    // Авто-копии по чатам курса. MG 12-10-2026 (вечер, по живой пробе):
    // «главное не в чаты, чаты сейчас не нужны, а в сторисы разных платформ»
    // — дефолт off; лейн остаётся приёмкой заголовка для сторис.
    'copies_enabled' => (bool) env('VIDEO_ANONS_COPIES_ENABLED', false),

    // Сторис по заголовку MG: MG 12-10-2026 (вечер) — «вооружить сейчас».
    // Публикация — approved-строка StoryPost (lane=persona), издатель —
    // планировщик stories:publish-story (каждый час, под madeline-локом).
    'stories_enabled' => (bool) env('VIDEO_ANONS_STORIES_ENABLED', true),

    /*
     * H6574: публичные поверхности поверх той же визы MG (заголовок-ответ).
     * Каждый сёрфейс — свой флаг; без визы MG не уходит ничего, VK — вдобавок
     * за механическим гейтом авторизованных групп (fail-closed, см. vk ниже).
     */
    // YouTube Shorts: видео из харвест-стора уходит в канал записей через
    // YouTube Data API (resumable upload). Дефолт off: включается только
    // после того, как в env появятся GOOGLE_YT_* креды.
    'yt_shorts_enabled' => (bool) env('VIDEO_ANONS_YT_SHORTS_ENABLED', false),

    // OAuth-креды канала записей (refresh token со scope youtube.upload).
    // Пусто = джоб отказывает явно (лог + уведомление админам), ничего не постит.
    'yt' => [
        'client_id' => env('GOOGLE_YT_CLIENT_ID'),
        'client_secret' => env('GOOGLE_YT_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_YT_REFRESH_TOKEN'),
        'privacy_status' => env('VIDEO_ANONS_YT_PRIVACY_STATUS', 'public'),
        'category_id' => env('VIDEO_ANONS_YT_CATEGORY_ID', '27'), // Education
    ],

    // VK-стена: пост уходит ТОЛЬКО в группу из авторизованного списка
    // (конвенция гейта VK_PUBLISH_AUTHORIZED эстейта: пустой список = публикация
    // отказывается; dry/тестовые пробы — на отдельную тестовую группу).
    'vk_wall_enabled' => (bool) env('VIDEO_ANONS_VK_WALL_ENABLED', false),

    'vk' => [
        // Токен сообщества с правом wall (не VK_BOT_TOKEN callback-бота!).
        'access_token' => env('VK_WALL_ACCESS_TOKEN'),
        'api_version' => '5.199',
        // Авторизованные группы: comma-separated id БЕЗ минуса
        // (88831040 = vk.com/samskrtamru). Пусто = пост запрещён.
        'authorized_group_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('VK_PUBLISH_AUTHORIZED_GROUPS', ''))))),
        // Куда постить видео-анонс. Должен быть в authorized_group_ids.
        'target_group_id' => (int) env('VK_WALL_TARGET_GROUP_ID', 0),
    ],

    /*
     * Авторы, чьё видео триггерит рассылку (решение 3: MG, Иван, преподаватель
     * курса). ids каноничны, username — fallback (менять изредка можно).
     * 427307131 — MG (super_admin, gasyoun@ya.ru, = первый из ADMIN_TELEGRAM_ID);
     * 1243819140 — Иван Молочко @ivanmolochko (автор видео 1067, живая проба 12-10).
     */
    'author_ids' => [
        427307131,
        1243819140,
    ],

    'author_usernames' => [
        'ivanmolochko',
        'edgars_leitans', // лектор всех чатов карты ниже
    ],

    // Доп. авторы по конкретному курсу (преподаватель курса), course key => ids.
    'course_authors' => [
        // 'leitana' => [123456789],
    ],

    /*
     * Каноническая карта курс → учебные чаты. Источник: groups.telegram_chat_id
     * (прод, 12-10-2026) + сверка с карточками Uprava tools/welcome_post/cards/
     * (у всех шести чатов одна команда курса — лектор Эдгар Лейтан, отдельные
     * строки Systema относят их к пяти разным курсам, поэтому ключ карты —
     * «команда курса», а не строка course_group).
     */
    'courses' => [
        'leitana' => [
            'title' => 'Курс Эдгара Лейтана',
            'chat_ids' => [
                -1001390357801, // Синтаксис санскрита
                -1002900195538, // Медленное чтение Йога-васиштхи
                -1002917231272, // Понедельник 20:00 (53) | Чат учеников (Кочергина гр.53)
                -1002930547299, // Грамматика по Кочергиной гр.58
                -1002964192002, // чат без строки в groups (карточка welcome_post)
                -1003022950274, // Грамматика по Кочергиной гр.54
            ],
        ],
    ],
];
