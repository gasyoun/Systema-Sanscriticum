<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Ссылка отписки от рекламной рассылки (152-ФЗ ст. 9 ч. 2, 38-ФЗ ст. 18).
 *
 * Подписанный URL без срока действия: адрес в параметре e (base64url), подпись
 * не даёт отписать чужой адрес перебором. Один и тот же URL работает и для
 * страницы подтверждения (GET), и для One-Click из почтового клиента
 * (POST, RFC 8058 — заголовок List-Unsubscribe-Post).
 */
final class Unsubscribe
{
    public static function url(string $email): string
    {
        return URL::signedRoute('unsubscribe', ['e' => self::encode($email)]);
    }

    public static function encode(string $email): string
    {
        return rtrim(strtr(base64_encode(mb_strtolower(trim($email))), '+/', '-_'), '=');
    }

    public static function decode(string $token): ?string
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        return $raw !== false && filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null;
    }
}
