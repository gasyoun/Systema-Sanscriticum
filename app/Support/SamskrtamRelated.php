<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H2918 — lock-listed contextual links to samskrtam.ru.
 *
 * Runtime never HTTP-fetches. Request path reads the committed allowlist + lock.
 */
final class SamskrtamRelated
{
    public const HOME_KEY = '_home';

    public const HEADING = 'Читать на samskrtam.ru';

    public const DONATE_SLUG = 'donat-na-razvitie-ors';

    /**
     * @return list<array{url: string, anchor: string}>
     */
    public static function linksFor(?string $key, ?string $allowlistPath = null, ?string $lockPath = null): array
    {
        $resolved = ($key === null || $key === '') ? self::HOME_KEY : $key;
        if ($resolved === self::DONATE_SLUG) {
            return [];
        }

        $entries = self::loadAllowlist($allowlistPath)[$resolved] ?? [];
        if ($entries === []) {
            return [];
        }

        $locked = self::lockedTwoHundred($lockPath);
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $url = trim((string) ($entry['url'] ?? ''));
            $anchor = trim((string) ($entry['anchor'] ?? ''));
            if ($url === '' || $anchor === '') {
                continue;
            }
            if (! isset($locked[$url])) {
                continue;
            }
            $out[] = ['url' => $url, 'anchor' => $anchor];
        }

        return $out;
    }

    /**
     * Unique allowlist URLs, preserving first-seen order.
     *
     * @return list<string>
     */
    public static function uniqueUrls(?string $allowlistPath = null): array
    {
        $seen = [];
        foreach (self::loadAllowlist($allowlistPath) as $entries) {
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $url = trim((string) ($entry['url'] ?? ''));
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return array<string, list<array{url: string, anchor: string}>>
     */
    public static function loadAllowlist(?string $path = null): array
    {
        $path ??= self::defaultAllowlistPath();
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $entries) {
            if (! is_string($key) || ! is_array($entries)) {
                continue;
            }
            $clean = [];
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $url = trim((string) ($entry['url'] ?? ''));
                $anchor = trim((string) ($entry['anchor'] ?? ''));
                if ($url === '' || $anchor === '') {
                    continue;
                }
                $clean[] = ['url' => $url, 'anchor' => $anchor];
            }
            $out[$key] = $clean;
        }

        return $out;
    }

    /**
     * URLs recorded as HTTP 200 in the committed lockfile.
     *
     * @return array<string, true>
     */
    public static function lockedTwoHundred(?string $path = null): array
    {
        $path ??= self::defaultLockPath();
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $rows = $decoded['urls'] ?? $decoded;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $status = (int) ($row['status'] ?? 0);
            if ($url !== '' && $status === 200) {
                $out[$url] = true;
            }
        }

        return $out;
    }

    public static function defaultAllowlistPath(): string
    {
        return database_path('data/seo/samskrtam_related.json');
    }

    public static function defaultLockPath(): string
    {
        return database_path('data/seo/samskrtam_related.lock.json');
    }
}
