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

    /**
     * @param  string|null  $screenshotBase  База src картинок. Null — raw GitHub
     *                                       (студент/куратор). Для бухгалтера —
     *                                       защищённый маршрут со storage.
     * @param  string  $screenshotPrefix  Что вырезаем из src Markdown (хвост
     *                                    после этого префикса остаётся).
     */
    public static function html(
        string $relativeSource,
        ?string $screenshotBase = null,
        string $screenshotPrefix = 'screenshots/',
    ): ?string {
        $path = base_path($relativeSource);

        if (! is_file($path)) {
            return null;
        }

        $markdown = file_get_contents($path);

        if ($markdown === false || trim($markdown) === '') {
            return null;
        }

        return self::resolveScreenshots(
            Str::markdown($markdown),
            $screenshotBase,
            $screenshotPrefix,
        );
    }

    private static function resolveScreenshots(
        string $html,
        ?string $screenshotBase,
        string $screenshotPrefix,
    ): string {
        $base = $screenshotBase ?? self::SCREENSHOT_BASE;
        $quoted = preg_quote($screenshotPrefix, '#');

        return (string) preg_replace(
            '#(<img[^>]+src=")'.$quoted.'#i',
            '$1'.$base,
            $html
        );
    }
}
