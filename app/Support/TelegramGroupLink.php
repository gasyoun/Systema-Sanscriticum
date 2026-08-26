<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H3557: кликабельная ссылка на учебную группу в Telegram из groups.telegram_chat_id.
 * Приватная супергруппа `-100…` открывается по https://t.me/c/<внутренний id>
 * для любого участника чата; прочие форматы (личные id, публичные каналы без
 * username в базе) ссылки не дают — возвращаем null и оставляем текст как есть.
 */
final class TelegramGroupLink
{
    public static function fromChatId(mixed $chatId): ?string
    {
        $id = trim((string) ($chatId ?? ''));

        if (preg_match('/^-100(\d{3,})$/', $id, $m) === 1) {
            return 'https://t.me/c/'.$m[1];
        }

        return null;
    }

    /**
     * HTML-анкр для TG-сообщений (parse_mode=HTML); без ссылки — просто
     * экранированный текст.
     */
    public static function anchor(mixed $chatId, string $label): string
    {
        $url = self::fromChatId($chatId);
        $safe = e($label !== '' ? $label : 'группа');

        if ($url === null) {
            return $safe;
        }

        return '<a href="'.$url.'">'.$safe.'</a>';
    }
}
