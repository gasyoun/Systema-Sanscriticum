<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resolve a public-disk relative path to a real file inside that disk.
 *
 * H2896 residual #9: CourseMaterialsArchiver used to call
 * Storage::disk('public')->path($relative) with no jail. ZIP downloads and
 * ZIP attachments stay allowed; only `..` / absolute / symlink-out paths are
 * refused.
 */
final class PublicDiskPath
{
    public static function absoluteFile(?string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim((string) $relativePath));
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        if (str_contains($relativePath, '..')) {
            return null;
        }
        if (preg_match('#^(?:[a-zA-Z]:)?/#', $relativePath) === 1) {
            return null;
        }

        $root = realpath(Storage::disk('public')->path(''));
        if ($root === false) {
            return null;
        }

        $candidate = Storage::disk('public')->path($relativePath);
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }
        if (! self::isInside($resolved, $root)) {
            return null;
        }

        return $resolved;
    }

    private static function isInside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
