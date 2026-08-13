<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GrammarLab\GrammarLabImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H2493 — vendor G1 Grammar Lab bundle from SanskritGrammar, then import.
 *
 *   php artisan grammar-lab:sync
 *   php artisan grammar-lab:sync --dry-run
 *   php artisan grammar-lab:sync --path=C:\...\SanskritGrammar\data\grammar_lab
 *
 * Does NOT flip features.grammar_lab or features.grammar_lab_semantic.
 */
class SyncGrammarLabFromSg extends Command
{
    protected $signature = 'grammar-lab:sync
                            {--path= : Absolute path to SG data/grammar_lab (default: sibling clone)}
                            {--dry-run : Validate + report only; write nothing}
                            {--skip-copy : Import already-vendored files only}';

    protected $description = 'Sync SanskritGrammar Grammar Lab G1 bundle into Systema and import idempotently';

    public function handle(GrammarLabImporter $importer): int
    {
        $vendor = $importer->vendorDir();
        $dry = (bool) $this->option('dry-run');

        if (! $this->option('skip-copy')) {
            $sourceDir = $this->resolveSourceDir();
            $this->copyFeeds($sourceDir, $vendor, $dry);
        }

        $report = $importer->import($vendor, $dry);
        foreach ($report as $key => $value) {
            if (is_scalar($value)) {
                $this->line($key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
            }
        }
        $this->line('grammar_lab_flag='.(config('features.grammar_lab') ? 'ON' : 'OFF').' (not modified)');
        $this->line('grammar_lab_semantic='.(config('features.grammar_lab_semantic') ? 'ON' : 'OFF').' (not modified)');

        if ($dry) {
            $this->info('dry-run: no write');
        } else {
            $this->info('Grammar Lab bundle imported');
        }

        return self::SUCCESS;
    }

    private function resolveSourceDir(): string
    {
        $opt = $this->option('path');
        if (is_string($opt) && $opt !== '') {
            return rtrim($opt, '\\/');
        }

        $candidates = [
            base_path('..'.DIRECTORY_SEPARATOR.'SanskritGrammar'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'grammar_lab'),
            dirname(base_path()).DIRECTORY_SEPARATOR.'SanskritGrammar'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'grammar_lab',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_file($candidate.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.'manifest.json')) {
                return $candidate;
            }
        }

        throw new RuntimeException('Cannot locate SG data/grammar_lab; pass --path=...');
    }

    private function copyFeeds(string $sourceDir, string $vendor, bool $dry): void
    {
        $manifestSrc = $sourceDir.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestSrc)) {
            throw new RuntimeException("Missing {$manifestSrc}");
        }
        $manifest = json_decode((string) file_get_contents($manifestSrc), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('manifest.json is not valid JSON');
        }

        $map = [
            'grammar_lab_bundle' => 'grammar_lab.json',
            'topic_vectors' => 'topic_vectors.json',
            'frozen_queries' => 'frozen_queries.json',
        ];

        $this->line('source='.$sourceDir);
        $this->line('vendor='.$vendor);

        foreach ($map as $feedId => $basename) {
            $src = $this->resolveFeedFile($sourceDir, $manifest, $feedId, $basename);
            $expected = $this->feedSha($manifest, $feedId);
            $actual = hash_file('sha256', $src);
            if ($expected !== '' && $expected !== $actual) {
                throw new RuntimeException("sha256 mismatch for {$feedId} (expected {$expected}, got {$actual})");
            }
            $dest = $vendor.DIRECTORY_SEPARATOR.$basename;
            $this->line("feed {$feedId} ok sha256={$actual}");
            if ($dry) {
                continue;
            }
            if (! is_dir($vendor) && ! mkdir($vendor, 0775, true) && ! is_dir($vendor)) {
                throw new RuntimeException("Cannot create {$vendor}");
            }
            if (! copy($src, $dest)) {
                throw new RuntimeException("Failed to copy {$src} → {$dest}");
            }
        }

        if (! $dry) {
            $destManifest = $vendor.DIRECTORY_SEPARATOR.'manifest.json';
            if (! copy($manifestSrc, $destManifest)) {
                throw new RuntimeException("Failed to copy manifest to {$destManifest}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function resolveFeedFile(string $sourceDir, array $manifest, string $feedId, string $basename): string
    {
        $sibling = $sourceDir.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.$basename;
        if (is_file($sibling)) {
            return $sibling;
        }
        $queries = $sourceDir.DIRECTORY_SEPARATOR.'queries'.DIRECTORY_SEPARATOR.$basename;
        if (is_file($queries)) {
            return $queries;
        }
        foreach ($manifest['feeds'] ?? [] as $feed) {
            if (! is_array($feed) || ($feed['id'] ?? null) !== $feedId) {
                continue;
            }
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($feed['path'] ?? ''));
            $abs = dirname($sourceDir, 2).DIRECTORY_SEPARATOR.$rel;
            if (is_file($abs)) {
                return $abs;
            }
        }

        throw new RuntimeException("Feed file missing for {$feedId} ({$basename})");
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function feedSha(array $manifest, string $id): string
    {
        foreach ($manifest['feeds'] ?? [] as $feed) {
            if (is_array($feed) && ($feed['id'] ?? null) === $id) {
                return (string) ($feed['sha256'] ?? '');
            }
        }

        return '';
    }
}
