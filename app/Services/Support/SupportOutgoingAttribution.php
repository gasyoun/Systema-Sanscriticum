<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Кто ответил в личке саппорт-аккаунта (H3233). Одна Madeline-сессия — from_id
 * троих не разделит. Протокол: в тексте Горбаченко стоит 🍎, Иван ставит 🐢;
 * остальные человеческие исходящие считаем Гасунсом.
 */
final class SupportOutgoingAttribution
{
    public const APPLE_MARKER = '🍎';

    public const TURTLE_MARKER = '🐢';

    public const GASUNS_MARKER = 'gasuns';

    public function markerFromOutgoingText(string $text): string
    {
        return str_contains($text, self::APPLE_MARKER)
            ? self::APPLE_MARKER
            : (str_contains($text, self::TURTLE_MARKER)
                ? self::TURTLE_MARKER
                : self::GASUNS_MARKER);
    }
}
