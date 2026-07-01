<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Общий безопасный рендер текста сообщений поддержки. Сообщения ботов приходят с
 * Telegram-форматированием (<b>, <i>…): экранируем всё, затем возвращаем как HTML
 * только узкий whitelist тегов без атрибутов. Единый источник для ChatMessage
 * (веб) и UnifiedMessage (единый inbox).
 */
final class SupportText
{
    /** @var list<string> */
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'code', 'pre'];

    public static function safeHtml(?string $text): string
    {
        $escaped = e((string) $text);

        foreach (self::ALLOWED_TAGS as $tag) {
            $escaped = str_replace(
                ['&lt;'.$tag.'&gt;', '&lt;/'.$tag.'&gt;'],
                ['<'.$tag.'>', '</'.$tag.'>'],
                $escaped,
            );
        }

        return nl2br($escaped);
    }
}
