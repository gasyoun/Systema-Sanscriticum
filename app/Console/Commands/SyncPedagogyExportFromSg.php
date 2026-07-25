<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * H1644 — thin hop: SanskritGrammar data/pedagogy_export → Systema vendor.
 *
 * Reads export_manifest.json (schema major ≥ 1), verifies the rq4_item_bank
 * feed hash when present, and copies it to resources/data/rq4_item_bank.json
 * (the path Rq4StudyController already loads). Does NOT flip features.rq4_study.
 *
 *   php artisan pedagogy:sync-sg-export
 *   php artisan pedagogy:sync-sg-export --path=C:\...\SanskritGrammar\data\pedagogy_export
 *   php artisan pedagogy:sync-sg-export --dry-run
 */
class SyncPedagogyExportFromSg extends Command
{
    protected $signature = 'pedagogy:sync-sg-export
                            {--path= : Absolute path to SG data/pedagogy_export (default: sibling clone)}
                            {--dry-run : Validate + report only; write nothing}
                            {--min-major=1 : Required schema_version major}';

    protected $description = 'Sync SanskritGrammar pedagogy_export (H1643) into Systema vendor paths (H1644)';

    private const TARGET_REL = 'data/rq4_item_bank.json';

    private const FEED_ID = 'rq4_item_bank';

    public function handle(): int
    {
        $exportDir = $this->resolveExportDir();
        $manifestPath = $exportDir.DIRECTORY_SEPARATOR.'export_manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("Missing export_manifest.json at {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error('export_manifest.json is not valid JSON');

            return self::FAILURE;
        }

        $version = (string) ($manifest['schema_version'] ?? '');
        $major = (int) explode('.', $version.'.0')[0];
        $minMajor = (int) $this->option('min-major');
        if ($version === '' || $major < $minMajor) {
            $this->error("schema_version {$version} does not satisfy min major {$minMajor}");

            return self::FAILURE;
        }

        $feed = $this->findFeed($manifest, self::FEED_ID);
        if ($feed === null) {
            $this->error('Manifest has no feed id='.self::FEED_ID);

            return self::FAILURE;
        }

        // Feed path in the SG manifest is repo-relative (data/pedagogy_export/rq4_item_bank.json).
        // Prefer a sibling file inside the export dir by basename.
        $source = $exportDir.DIRECTORY_SEPARATOR.basename((string) $feed['path']);
        if (! is_file($source)) {
            $this->error("Feed file missing: {$source}");

            return self::FAILURE;
        }

        $expected = (string) ($feed['sha256'] ?? '');
        if ($expected !== '') {
            $actual = hash_file('sha256', $source);
            if ($actual !== $expected) {
                $this->error('sha256 mismatch for '.self::FEED_ID." (expected {$expected}, got {$actual})");

                return self::FAILURE;
            }
        }

        $bank = json_decode((string) file_get_contents($source), true);
        if (! is_array($bank) || ! isset($bank['sets']) || ! is_array($bank['sets'])) {
            $this->error('rq4_item_bank JSON missing sets[]');

            return self::FAILURE;
        }

        $firstItem = $this->firstItemId($bank);
        $itemCount = $this->countItems($bank);
        $target = resource_path(self::TARGET_REL);

        $this->line("schema_version={$version}");
        $this->line('items='.$itemCount);
        $this->line('first_item='.($firstItem ?? '(none)'));
        $this->line("source={$source}");
        $this->line("target={$target}");
        $this->line('rq4_study_flag='.(config('features.rq4_study') ? 'ON' : 'OFF').' (not modified)');

        if ($this->option('dry-run')) {
            $this->info('dry-run: no write');

            return self::SUCCESS;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        if (! copy($source, $target)) {
            $this->error("Failed to copy to {$target}");

            return self::FAILURE;
        }

        $this->info('Synced rq4_item_bank.json from SG pedagogy_export');

        return self::SUCCESS;
    }

    private function resolveExportDir(): string
    {
        $opt = $this->option('path');
        if (is_string($opt) && $opt !== '') {
            return rtrim($opt, "\\/");
        }

        // Default: ../SanskritGrammar/data/pedagogy_export relative to this repo root.
        $candidate = base_path('..'.DIRECTORY_SEPARATOR.'SanskritGrammar'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'pedagogy_export');
        if (is_dir($candidate)) {
            return $candidate;
        }

        // Worktree layout: Systema-Sanscriticum-h1644 sibling of SanskritGrammar-h1643
        $alt = base_path('..'.DIRECTORY_SEPARATOR.'SanskritGrammar-h1643'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'pedagogy_export');
        if (is_dir($alt)) {
            return $alt;
        }

        throw new RuntimeException(
            'Cannot locate SG data/pedagogy_export; pass --path=... explicitly'
        );
    }

    /** @param array<string,mixed> $manifest */
    private function findFeed(array $manifest, string $id): ?array
    {
        $feeds = $manifest['feeds'] ?? null;
        if (! is_array($feeds)) {
            return null;
        }
        foreach ($feeds as $feed) {
            if (is_array($feed) && ($feed['id'] ?? null) === $id) {
                return $feed;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $bank */
    private function firstItemId(array $bank): ?string
    {
        foreach ($bank['sets'] as $phaseItems) {
            if (! is_array($phaseItems) || $phaseItems === []) {
                continue;
            }
            $item = $phaseItems[0];
            if (is_array($item)) {
                return (string) ($item['slp1'] ?? $item['root'] ?? json_encode($item));
            }
        }

        return null;
    }

    /** @param array<string,mixed> $bank */
    private function countItems(array $bank): int
    {
        $n = 0;
        foreach ($bank['sets'] as $phaseItems) {
            if (is_array($phaseItems)) {
                $n += count($phaseItems);
            }
        }

        return $n;
    }
}
