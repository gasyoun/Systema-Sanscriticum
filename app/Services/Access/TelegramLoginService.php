<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «Telegram-вход» в кабинет (CABINET_ADOPTION_ROADMAP P2, 28-08-2026). Студент
 * пишет студент-боту /start или /вход — если его Telegram уже привязан
 * (users.telegram_id), бот выдаёт одноразовую magic-ссылку входа в тот же чат.
 *
 * Владение Telegram здесь и есть фактор подлинности — то же доверие, что у
 * deep-link привязки из кабинета, только в обратную сторону. Гарантии ссылки —
 * те же, что у сброса пароля ({@see MagicLinkToken}): plaintext уходит в чат,
 * в БД только SHA-256, короткий TTL, атомарное гашение при первом клике,
 * назначение гасится только на своём маршруте /tg-login/{token}.
 *
 * Флаги: features.telegram_cabinet_login (мастер) и
 * features.telegram_cabinet_email_link (привязка по email заказа прямо в чате —
 * отдельный осознанный риск, см. config/features.php).
 */
class TelegramLoginService
{
    /** Назначение magic-токена «Telegram-вход» — гасится только на /tg-login/{token}. */
    public const MAGIC_PURPOSE = 'tg_login';

    /** TTL ссылки в чате (минуты): студент ждёт «здесь и сейчас», долгий TTL не нужен. */
    public const TTL_MINUTES = 15;

    /** Не больше стольких ссылок на один chat_id за окно (анти-спам бота). */
    public const MAX_LINKS_PER_CHAT = 5;

    public function isEnabled(): bool
    {
        return (bool) config('features.telegram_cabinet_login');
    }

    /** Сценарий email-привязки работает только при включённом мастере. */
    public function isEmailLinkEnabled(): bool
    {
        return $this->isEnabled() && (bool) config('features.telegram_cabinet_email_link');
    }

    /** Одноразовая ссылка входа (URL) для студента. */
    public function issueLoginLink(User $user): string
    {
        $token = MagicLinkToken::issueFor($user, self::MAGIC_PURPOSE, self::TTL_MINUTES);

        return url('/tg-login/'.$token);
    }

    /** Анти-спам окно на chat_id: true = ещё можно выдать ссылку. */
    public function allowChat(int $chatId): bool
    {
        $key = 'tg-cabinet-login:'.$chatId;

        if (RateLimiter::tooManyAttempts($key, self::MAX_LINKS_PER_CHAT)) {
            return false;
        }

        RateLimiter::hit($key, self::TTL_MINUTES * 60);

        return true;
    }
}
