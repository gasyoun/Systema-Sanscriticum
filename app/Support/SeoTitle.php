<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds a search-result title capped at 60 characters.
 *
 * One short brand ("ОРС") is appended to every page; the base is truncated only
 * when it would push the result past 60. Existing brand suffixes are stripped
 * first, so a title that already ends with "— Общество ревнителей санскрита"
 * never gets two brands.
 */
final class SeoTitle
{
    public const MAX_LENGTH = 60;

    private const BRAND = 'Общество ревнителей санскрита';

    private const SHORT_BRAND = 'ОРС';

    public static function render(?string $base): string
    {
        $base = self::stripBrand(self::clean($base));

        if ($base === '') {
            return self::BRAND;
        }

        $budget = self::MAX_LENGTH - mb_strlen(' | '.self::SHORT_BRAND);
        if (mb_strlen($base) > $budget) {
            $base = self::truncate($base, $budget);
        }

        return $base.' | '.self::SHORT_BRAND;
    }

    /**
     * Clean + truncate without adding a brand (promo pages already carry their
     * own naming and should not be re-branded).
     */
    public static function plain(?string $base): string
    {
        $base = self::stripBrand(self::clean($base));

        return $base === '' ? '' : self::truncate($base, self::MAX_LENGTH);
    }

    private static function clean(?string $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function stripBrand(string $value): string
    {
        $pattern = '/\s*[|—–-]\s*(?:Общество ревнителей санскрита|ОРС)\s*$/iu';

        // Handles stacked suffixes such as "… — Общество ревнителей санскрита — ОРС".
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace($pattern, '', $value);
            if ($stripped === null || $stripped === $value) {
                break;
            }
            $value = trim($stripped);
        }

        return $value;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:—–-").'…';
    }
}
