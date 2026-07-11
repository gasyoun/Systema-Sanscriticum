<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Session-scoped маркер владения гостевым тредом поддержки (H536 Phase 2).
 *
 * НЕ внешняя идентичность (в `social_accounts` не пишется) — эфемерный токен на
 * сессию, которым анонимный посетитель владеет своим `SupportConversation`.
 * Читается broadcast-guard'ом `chat_guest` (routes/channels.php) и публичным
 * post-эндпоинтом (Phase 3).
 */
final class GuestChat
{
    public const SESSION_KEY = 'chat_guest_token';

    /** Токен текущего гостя; создаётся при первом обращении. */
    public static function token(): string
    {
        $token = session(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            session([self::SESSION_KEY => $token]);
        }

        return $token;
    }

    /** Токен без создания — для guard'а и чтения (null, если гость ещё не писал). */
    public static function currentToken(): ?string
    {
        $token = session(self::SESSION_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
