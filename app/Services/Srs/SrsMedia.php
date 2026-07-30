<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsDeck;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve card-field media paths (Anki import audio/image) to public URLs.
 *
 * Import publishes files to the public disk under {@see publicDiskPrefix}.
 * Legacy fields still store seed-relative paths like `media/foo.mp3`; those are
 * rewritten via the deck slug `anki-{courseId}-level-N`.
 */
final class SrsMedia
{
    public static function publicDiskPrefix(string $courseId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '', $courseId) ?: 'unknown';

        return "srs/anki_{$safe}";
    }

    /**
     * Publish every regular file from $sourceMediaDir into the public disk.
     *
     * @return int number of files written
     */
    public static function publishDirectory(string $sourceMediaDir, string $courseId): int
    {
        if (! is_dir($sourceMediaDir)) {
            return 0;
        }

        $prefix = self::publicDiskPrefix($courseId);
        $disk = Storage::disk('public');
        $count = 0;

        $entries = scandir($sourceMediaDir);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $sourceMediaDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($from)) {
                continue;
            }
            $safe = basename($entry);
            if ($safe === '' || $safe === '.' || $safe === '..') {
                continue;
            }
            $disk->put($prefix.'/'.$safe, (string) file_get_contents($from));
            $count++;
        }

        return $count;
    }

    /**
     * Turn a card field path into a browser URL, or null if unusable.
     */
    public static function url(?string $path, ?SrsDeck $deck = null): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return $path;
        }

        $relative = self::publicDiskRelative($path, $deck);
        if ($relative === null) {
            return null;
        }

        if (! Storage::disk('public')->exists($relative)) {
            return null;
        }

        return asset('storage/'.$relative);
    }

    /**
     * Normalize a field value to a path relative to the public disk root.
     */
    public static function publicDiskRelative(string $path, ?SrsDeck $deck = null): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        // Already storage-relative (written by import after publish).
        if (str_starts_with($normalized, 'srs/anki_')) {
            return self::assertNoTraversal($normalized);
        }

        // Legacy seed-relative: media/foo.mp3 → srs/anki_{courseId}/foo.mp3
        if (str_starts_with($normalized, 'media/')) {
            $courseId = self::courseIdFromDeck($deck);
            if ($courseId === null) {
                return null;
            }
            $base = basename($normalized);
            if ($base === '' || $base === '.' || $base === '..') {
                return null;
            }

            return self::publicDiskPrefix($courseId).'/'.$base;
        }

        // Bare basename with deck context
        if (! str_contains($normalized, '/') && $deck !== null) {
            $courseId = self::courseIdFromDeck($deck);
            if ($courseId !== null) {
                return self::publicDiskPrefix($courseId).'/'.basename($normalized);
            }
        }

        return self::assertNoTraversal($normalized);
    }

    /**
     * Rewrite a seed-relative media path for storage on the card fields JSON.
     */
    public static function fieldPathForStorage(string $path, string $courseId): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (str_starts_with($path, 'srs/anki_')) {
            return ltrim($path, '/');
        }
        $base = basename($path);
        if ($base === '' || $base === '.' || $base === '..') {
            return '';
        }

        return self::publicDiskPrefix($courseId).'/'.$base;
    }

    private static function courseIdFromDeck(?SrsDeck $deck): ?string
    {
        if ($deck === null || $deck->slug === null) {
            return null;
        }
        // anki-{courseId}-level-{n}
        if (preg_match('/^anki-(.+)-level-\d+$/', (string) $deck->slug, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function assertNoTraversal(string $path): ?string
    {
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
