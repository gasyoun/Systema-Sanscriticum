<?php

declare(strict_types=1);

/**
 * Контрол-бот MG для голосования @DECIDE из Telegram (H6452).
 *
 * Отдельный бот (НЕ @samskrte_ops_bot — у того живой вебхук business-апдейтов,
 * long-poll снял бы его; НЕ личный аккаунт — правило D1): токен создаётся
 * через BotFather один раз и кладётся в MG_DECIDE_BOT_TOKEN.
 *
 * Карточки @DECIDE бот читает из clone gasyoun/vote-inbox (файл
 * mg-decide/cards.json, кладёт Uprava tools/mg_decide_cards.py); вердикт
 * пишется туда же как decisions/<sheet_id>/pack-NN.json по
 * schema/decisions-pack.schema.json — существующий review_decisions_watcher
 * подхватывает без изменений. Uprava на сервер не клонируется и не фетчится
 * (в его истории stenogrammy/ — 152-ФЗ, забор целиком запрещён).
 */
return [
    // Токен бота из BotFather. Пусто — поллер и /decide отказывают с подсказкой.
    'bot_token' => env('MG_DECIDE_BOT_TOKEN', ''),

    'bot_username' => env('MG_DECIDE_BOT_USERNAME', ''),

    // Рубильник поллера: дефолт false, чтобы случайный запуск не начал
    // getUpdates без настроенного бота (урок telegram:poll-student).
    'poll_enabled' => (bool) env('MG_DECIDE_POLL_ENABLED', false),

    'poll_timeout_seconds' => (int) env('MG_DECIDE_POLL_TIMEOUT_SECONDS', 50),
    'poll_retry_seconds' => (int) env('MG_DECIDE_POLL_RETRY_SECONDS', 10),
    'poll_max_lifetime_seconds' => (int) env('MG_DECIDE_POLL_MAX_LIFETIME_SECONDS', 3600),

    // Вердикты только MG: Telegram user id, которым разрешены команды.
    // Пусто — бот молчит на всё (fail-closed).
    'allowed_tg_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('MG_DECIDE_ALLOWED_TG_IDS', ''))
    ))),

    // Локальный clone gasyoun/vote-inbox на этом хосте.
    'inbox_path' => env('MG_DECIDE_INBOX_PATH', '/var/www/vote-inbox'),

    'pack_branch' => env('MG_DECIDE_PACK_BRANCH', 'master'),

    // ≤4 карточек за вызов /decide (правила interactive-grilling).
    'cards_per_call' => (int) env('MG_DECIDE_CARDS_PER_CALL', 4),

    // Идентичность коммитов пакета в vote-inbox.
    'git_author_name' => env('MG_DECIDE_GIT_NAME', 'mg-decide-bot'),
    'git_author_email' => env('MG_DECIDE_GIT_EMAIL', 'mg-decide-bot@users.noreply.github.com'),
];
