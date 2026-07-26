<?php

/**
 * Очередь «Техника» из userbot (ЛС + учебные группы).
 * Assignee и peers — env; keywords — дефолтный RU-список, переопределяется env.
 */
return [

    /** users.id закреплённого техспециалиста (авто-assigned_to). */
    'assignee_user_id' => env('SUPPORT_TECH_ASSIGNEE_USER_ID')
        ? (int) env('SUPPORT_TECH_ASSIGNEE_USER_ID')
        : null,

    /**
     * Учебные чаты, где сидит userbot: @username или numeric id через запятую.
     * Синк всегда подтягивает эти peers (в дополнение к dialog_limit).
     */
    'group_peers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TELEGRAM_SUPPORT_TECH_GROUP_PEERS', '')),
    ))),

    /**
     * Username userbot-аккаунта без @ (для детекта @mention в тексте/entities).
     * Если пусто — mention ловится только через messageEntityMentionName + self id
     * (когда live-синк знает getSelf).
     */
    'bot_username' => ltrim((string) env('TELEGRAM_SUPPORT_USERNAME', ''), '@') ?: null,

    /**
     * Ключевые слова / фразы (через запятую в env переопределяют весь список).
     * Match = OR. Регистр не важен.
     */
    'keywords' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('SUPPORT_TECH_KEYWORDS') ?: implode(',', [
            'кабинет',
            'доступ',
            'пароль',
            'логин',
            'не открывается',
            'не загружается',
            'zoom',
            'зум',
            'ссылка',
            'вебинар',
            'kinescope',
            'ошибка',
            'не работает',
            'белый экран',
            'не слышно',
            'микрофон',
            'вход',
            'регистрац',
            'не могу войти',
            'не могу зайти',
            'сайт',
            'приложение',
        ]))),
    ))),
];
