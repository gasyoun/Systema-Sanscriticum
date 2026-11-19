<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Поля-секреты в админке (токены ботов, webhook-секреты, пароль студента /
 * преподавателя) — не место для сохранённого пароля браузера.
 *
 * Инцидент 15-10-2026: `->password()`-поле «Bot Token» стоит сразу после
 * текстового «Username бота», и браузер принимал пару за форму входа —
 * при каждом открытии «Глобальных настроек» вписывал сохранённый пароль
 * сайта вместо токена, и после сохранения @samskrte_bot замолкал (404 на
 * любой вызов Telegram API). Поэтому у секретов autocomplete="new-password"
 * (Chromium не подставляет сохранённое) + атрибуты-«не трогать» для
 * менеджеров паролей, а у токена Telegram — проверка формата на сервере.
 */
final class NoAutofill
{
    /** 1Password / LastPass / Bitwarden / Dashlane: не предлагать и не подставлять. */
    public const IGNORE_MANAGERS = [
        'data-1p-ignore' => 'true',
        'data-lpignore' => 'true',
        'data-bwignore' => 'true',
        'data-form-type' => 'other',
    ];

    /** Токен от @BotFather: «<id бота>:<35 символов>». */
    public const TELEGRAM_BOT_TOKEN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';

    public const TELEGRAM_BOT_TOKEN_MESSAGE = 'Это не токен от @BotFather — формат 123456789:AA…. Если поле заполнил браузер сохранённым паролем, вставьте токен заново.';

    /** secret_token вебхука: Telegram принимает только A-Z a-z 0-9 _ - (1–256). */
    public const TELEGRAM_WEBHOOK_SECRET = '/^[A-Za-z0-9_-]{1,256}$/';

    public const TELEGRAM_WEBHOOK_SECRET_MESSAGE = 'Только латиница, цифры, «_» и «-» — иначе Telegram не примет вебхук.';
}
