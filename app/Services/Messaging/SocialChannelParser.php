<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Парсит поле social и определяет канал доставки lead-magnet'а.
 * Возвращает {channel: telegram|vk|max|null, handle: string|null}.
 * Instagram распознаём но возвращаем null — нет интеграции, должен сработать fallback.
 */
final class SocialChannelParser
{
    /**
     * @return array{channel: string|null, handle: string|null}
     */
    public function parse(?string $social): array
    {
        if (empty($social)) {
            return ['channel' => null, 'handle' => null];
        }

        $value = trim($social);

        // --- Telegram ---
        if (preg_match('/^@([\w]{3,})$/', $value, $m)) {
            return ['channel' => 'telegram', 'handle' => $m[1]];
        }
        if (preg_match('~(?:https?://)?t\.me/(?!joinchat/)(?!c/)(?!s/)([\w]{3,})~i', $value, $m)) {
            return ['channel' => 'telegram', 'handle' => $m[1]];
        }
        if (preg_match('~tg://resolve\?domain=([\w]{3,})~i', $value, $m)) {
            return ['channel' => 'telegram', 'handle' => $m[1]];
        }

        // --- VK ---
        if (preg_match('~(?:https?://)?(?:www\.)?vk\.com/([\w.]{1,32})~i', $value, $m)) {
            return ['channel' => 'vk', 'handle' => $m[1]];
        }
        if (preg_match('/^vk[:\s]+([\w.]{1,32})$/i', $value, $m)) {
            return ['channel' => 'vk', 'handle' => $m[1]];
        }

        // --- Max ---
        if (preg_match('~(?:https?://)?(?:www\.)?max\.ru/@?([\w]{3,})~i', $value, $m)) {
            return ['channel' => 'max', 'handle' => $m[1]];
        }
        if (preg_match('/^max[:\s]+@?([\w]{3,})$/i', $value, $m)) {
            return ['channel' => 'max', 'handle' => $m[1]];
        }

        // Instagram распознан, но не доставляем — fallback на дефолт лендинга.
        return ['channel' => null, 'handle' => null];
    }
}
