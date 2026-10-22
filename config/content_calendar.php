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
    ],

    // Бот, назначенный админом (с правом публикации) во всех TG-каналах выше.
    'tg_bot_token' => env('CONTENT_CAL_TG_BOT_TOKEN', ''),

    // После стольких неудачных тиков площадка помечается failed и админам
    // уходит уведомление; дальше её не повторяем.
    'max_attempts' => 3,

    'vk_api_version' => '5.199',

    /*
     | VK ID user-токен (админ сообществ) живёт час и ротируется вместе с
     | refresh_token. Хранится в БД (vk_user_tokens, encrypted), единственный
     | владелец — прод: vk:token-refresh обновляет его по расписанию.
     */
    'vk_token_refresh_minutes' => 20,

];
