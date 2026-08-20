<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Рендер закоммиченного Markdown-гида в HTML (H3212).
 *
 * Путь — константа вызывающего кода, никогда не из запроса: вывод
 * вставляется неэкранированным. TeacherGuide в волне 1 на этот класс
 * не переводится.
 */
final class MarkdownGuide
{
    public const SCREENSHOT_BASE = 'https://raw.githubusercontent.com/gasyoun/Systema-Sanscriticum/main/docs/screenshots/';

    public static function html(string $relativeSource): ?string
    {
        $path = base_path($relativeSource);

        if (! is_file($path)) {
            return null;
        }

        $markdown = file_get_contents($path);

        if ($markdown === false || trim($markdown) === '') {
            return null;
        }

        return self::resolveScreenshots(Str::markdown($markdown));
    }

    private static function resolveScreenshots(string $html): string
    {
        return (string) preg_replace(
            '#(<img[^>]+src=")screenshots/#i',
            '$1'.self::SCREENSHOT_BASE,
            $html
        );
    }
}
