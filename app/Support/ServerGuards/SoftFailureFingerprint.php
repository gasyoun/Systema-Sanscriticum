<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

/**
 * Stable soft-failure fingerprint for cabinet:probe TG anti-spam (H2335).
 *
 * Fuse / dirty messages embed ISO timestamps and git SHAs that change while the
 * *class* of problem is unchanged — hashing the raw text re-fires soft TG every
 * time the breaker line is rewritten (census 06-08-2026: 0 critical / 107 soft).
 *
 * Normalize to class-level tokens, then SHA-256 the sorted set.
 */
final class SoftFailureFingerprint
{
    /**
     * @param  list<array{message?: string, severity?: string}|string>  $softFails
     */
    public static function hash(array $softFails): string
    {
        $normalized = [];
        foreach ($softFails as $f) {
            $message = is_string($f) ? $f : (string) ($f['message'] ?? '');
            $normalized[] = self::normalizeMessage($message);
        }
        $normalized = array_values(array_unique(array_filter($normalized, static fn (string $s): bool => $s !== '')));
        sort($normalized);

        return hash('sha256', implode("\n", $normalized));
    }

    public static function normalizeMessage(string $message): string
    {
        $m = trim($message);
        if ($m === '') {
            return '';
        }

        // ISO-8601 instants (fuse last-line timestamps).
        $m = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z/', 'TS', $m);
        // Date-only stamps.
        $m = (string) preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', 'DATE', $m);
        // Git short/long SHAs.
        $m = (string) preg_replace('/\b[0-9a-f]{7,40}\b/i', 'SHA', $m);
        // Absolute Windows/Unix app paths — keep basename class only for noise.
        $m = (string) preg_replace('#/[A-Za-z0-9._/-]*storage/auto_deploy\.disabled#', 'storage/auto_deploy.disabled', $m);
        $m = (string) preg_replace('#\\\\[A-Za-z0-9._\\\\-]*storage\\\\auto_deploy\.disabled#', 'storage/auto_deploy.disabled', $m);

        // Soft auto-deploy fuse: collapse to tag class when present.
        if (preg_match('/\[(blocked-preflight|blocked-dirty|timeout-alive|rolled-back)\]/', $m, $tag)) {
            return 'guards/auto-deploy:fuse:'.$tag[1];
        }
        if (str_contains($m, 'tracked dirty')) {
            // Path list after "…остановятся): path1, path2 — …" (auditor wording).
            $paths = [];
            if (
                preg_match('/остановятся\)\s*:\s*(.+?)\s*—/u', $m, $pm)
                || preg_match('/tracked dirty[^:]*:\s*(.+?)\s*—/u', $m, $pm)
                || preg_match('/tracked dirty[^:]*:\s*(.+)$/u', $m, $pm)
            ) {
                foreach (preg_split('/\s*,\s*/', trim($pm[1])) ?: [] as $p) {
                    $p = trim($p);
                    $p = (string) preg_replace('/\s*…\+\d+$/u', '', $p);
                    if ($p !== '' && $p !== '…' && ! str_contains($p, 'tracked dirty')) {
                        $paths[] = $p;
                    }
                }
            }
            sort($paths);

            return 'guards/auto-deploy:dirty:'.implode(',', $paths);
        }
        if (str_contains($m, 'managed-file') || str_contains($m, 'разошёлся с репозиторием')) {
            return 'guards/managed-file';
        }
        if (str_contains($m, 'systema-auto-deploy-run.sh') && str_contains($m, 'crontab')) {
            return 'guards/auto-deploy:cron-missing';
        }

        $m = (string) preg_replace('/\s+/u', ' ', $m);

        return $m;
    }
}
