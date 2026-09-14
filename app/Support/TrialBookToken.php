<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HMAC-токен записи на пробное занятие из публичного виджета (H3248).
 *
 * Публичный фид расписания не отдаёт числовых id (граница безопасности
 * H1427), поэтому идентификатор строки едет внутри URL-safe подписанного
 * токена `<schedule_id>.<base64url(hmac-sha256)>` с ключом APP_KEY.
 * Токен не является стабильной permalink-ссылкой и сверяется constant-time.
 */
class TrialBookToken
{
    private const CONTEXT = 'trial-book-v1';

    public static function for(int $scheduleId): string
    {
        return $scheduleId.'.'.self::signature($scheduleId);
    }

    public static function resolve(string $token): ?int
    {
        $dot = strrpos($token, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($token) - 1) {
            return null;
        }

        $scheduleId = substr($token, 0, $dot);
        if (! ctype_digit($scheduleId)) {
            return null;
        }

        if (! hash_equals(self::signature((int) $scheduleId), substr($token, $dot + 1))) {
            return null;
        }

        return (int) $scheduleId;
    }

    private static function signature(int $scheduleId): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && $decoded !== '') {
                $key = $decoded;
            }
        }

        $mac = hash_hmac('sha256', self::CONTEXT.':'.$scheduleId, $key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
