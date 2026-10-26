<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Календарь контента: площадки кросспостинга (22-10-2026)
|--------------------------------------------------------------------------
|
| Один слот календаря уходит сразу в несколько площадок; у каждого слота в
| форме — галочки по этим ключам (null в slot.destinations = все включённые).
| Площадка с пустым chat_id / group_id считается выключенной и в форме не
| показывается. Ключи стабильны — на них завязаны строки
| content_calendar_publications, переименовывать нельзя.
|
| VK-площадка публикует только если её group_id есть в
| VK_PUBLISH_AUTHORIZED_GROUPS (тот же механический гейт, что у
| PublishVideoAnonsVkWallJob: пустой список = публикация запрещена).
|
*/

return [

    'destinations' => [
        'tg_opoveshchenie' => [
            'platform' => 'telegram',
            'label' => 'TG · Оповещение санскритян',
            'chat_id' => env('CONTENT_CAL_TG_OPOVESHCHENIE', ''),
        ],
        'tg_ors' => [
            'platform' => 'telegram',
            'label' => 'TG · Общество ревнителей санскрита',
            'chat_id' => env('CONTENT_CAL_TG_ORS', ''),
        ],
        'tg_institut' => [
            'platform' => 'telegram',
            'label' => 'TG · Институт исследования санскрита',
            'chat_id' => env('CONTENT_CAL_TG_INSTITUT', ''),
        ],
        'vk_opoveshchenie' => [
            'platform' => 'vk',
            'label' => 'VK · Оповещение санскритян',
            // 236973776 = vk.com/samskrte
            'group_id' => (int) env('CONTENT_CAL_VK_OPOVESHCHENIE', 0),
        ],
        'vk_ors' => [
            'platform' => 'vk',
            'label' => 'VK · Общество ревнителей санскрита',
            // 88831040 = vk.com/samskrtamru
            'group_id' => (int) env('CONTENT_CAL_VK_ORS', 0),
        ],
        'vk_institut' => [
            'platform' => 'vk',
            'label' => 'VK · Институт исследования санскрита',
            'group_id' => (int) env('CONTENT_CAL_VK_INSTITUT', 0),
        ],

        // Сторис — только у слотов с картинкой для сторис, после постов.
        // Telegram: аккаунт, подключённый к Business-боту (Bot API postStory,
        // нужен can_manage_stories), как у сторис из видео каналов.
        'tg_story_business' => [
            'platform' => 'tg_business_story',
            'label' => 'Сторис · Telegram Business',
            'enabled' => (bool) env('CONTENT_CAL_TG_BUSINESS_STORY', false),
        ],
        // VK: сторис сообщества; у VK-токена должно быть право stories, гейт
        // VK_PUBLISH_AUTHORIZED_GROUPS — как у постов.
        'vk_story_opoveshchenie' => [
            'platform' => 'vk_story',
            'label' => 'Сторис · VK Оповещение санскритян',
            'group_id' => (int) env('CONTENT_CAL_VK_OPOVESHCHENIE', 0),
        ],
        'vk_story_ors' => [
            'platform' => 'vk_story',
            'label' => 'Сторис · VK Общество ревнителей санскрита',
            'group_id' => (int) env('CONTENT_CAL_VK_ORS', 0),
        ],
        'vk_story_institut' => [
            'platform' => 'vk_story',
            'label' => 'Сторис · VK Институт исследования санскрита',
            'group_id' => (int) env('CONTENT_CAL_VK_INSTITUT', 0),
        ],
    ],

    // Публичный @username канала «Оповещение» — ссылка из сторис на вышедший
    // пост (t.me/c/… для числового chat_id открывается только участникам).
    'tg_public_username' => env('CONTENT_CAL_TG_PUBLIC_USERNAME', 'samskrte'),

    // Бот — @zapisi_ORSbot (MarketingSetting.zapisi_bot_token), админ с правом
    // публикации во всех TG-каналах выше. Переменная — только чтобы
    // подменить бота; пусто = бот записей.
    'tg_bot_token' => env('CONTENT_CAL_TG_BOT_TOKEN', ''),

    /*
     | Пост, вышедший в канале-источнике (его публикует любой админ канала,
     | в т.ч. отложкой Telegram), становится слотом календаря и уходит в
     | остальные площадки. channel_post приходит в общий поток апдейтов
     | @zapisi_ORSbot (ProcessTelegramZapisiUpdate: вебхук через входной узел
     | или аварийный zapisi:poll) — своего вебхука у календаря нет. OFF по
     | умолчанию.
     */
    'tg_ingest_enabled' => (bool) env('CONTENT_CAL_TG_INGEST', false),
    'tg_ingest_source' => 'tg_opoveshchenie',
    // Альбом приходит отдельными апдейтами; ждём, пока придут все части.
    'tg_album_wait_seconds' => 20,

    // После стольких неудачных тиков площадка помечается failed и админам
    // уходит уведомление; дальше её не повторяем.
    'max_attempts' => 3,

    // Слот, чей publish_at прошёл больше чем столько часов назад и который
    // ещё никуда не публиковался, не публикуется, а уходит в draft
    // (meta.stale_hold): старые посты не выстреливают пачкой.
    'max_lateness_hours' => 24,

    // VK ID user-токен админа сообществ — не здесь, а в БД (vk_user_tokens,
    // encrypted): живёт час, его обновляет vk:token-refresh, импорт — vk:token-import.
    'vk_api_version' => '5.199',

];
