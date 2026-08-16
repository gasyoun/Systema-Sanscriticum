<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitize teacher/admin Tiptap HTML before `{!! !!}` (H2896 residual #8).
 *
 * Uses the already-locked `symfony/html-sanitizer` (Laravel 13). Safe elements
 * only: no script, no event handlers, no javascript: URLs. Relative /storage
 * media and http(s) links stay — that is what the Tiptap `simple` profile writes.
 */
final class RichHtml
{
    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeLinks()
            ->allowRelativeMedias();

        return new HtmlSanitizer($config);
    }
}
