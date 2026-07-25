<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1644 — thin pedagogy_export → vendor sync (does not enable features.rq4_study).
 */
class SyncPedagogyExportFromSgTest extends TestCase
{
    public function test_sync_copies_bank_and_leaves_flag_off(): void
    {
        config(['features.rq4_study' => false]);

        $exportDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sg_pedagogy_export_'.uniqid();
        mkdir($exportDir);

        $bank = [
            'method' => 'fixture',
            'sets' => [
                'pre_test' => [
                    ['root' => 'yat', 'slp1' => 'yat', 'ryad' => 'A1', 'tip' => 'II', 'set' => 'veṭ'],
                ],
                'post_test' => [],
                'retention_test' => [],
            ],
        ];
        $bankPath = $exportDir.DIRECTORY_SEPARATOR.'rq4_item_bank.json';
        file_put_contents($bankPath, json_encode($bank, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $sha = hash_file('sha256', $bankPath);

        $manifest = [
            'schema_version' => '1.0.0',
            'generated_at' => '2026-07-25T00:00:00Z',
            'generator' => 'tests',
            'feeds' => [
                [
                    'id' => 'rq4_item_bank',
                    'path' => 'data/pedagogy_export/rq4_item_bank.json',
                    'sha256' => $sha,
                    'rights' => 'aggregate-public-safe',
                    'consumer_hint' => 'Systema resources/data/rq4_item_bank.json',
                ],
            ],
            'last_mile_hop_status' => [
                'A_reader' => 'shipped-in-Systema',
                'B_srs' => 'shipped-in-Systema',
                'C_difficulty' => 'shipped-in-Systema',
                'rq4_harness' => 'shipped-flag-off',
            ],
        ];
        file_put_contents(
            $exportDir.DIRECTORY_SEPARATOR.'export_manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $target = resource_path('data/rq4_item_bank.json');
        $backup = is_file($target) ? file_get_contents($target) : null;

        try {
            $code = Artisan::call('pedagogy:sync-sg-export', [
                '--path' => $exportDir,
            ]);
            $this->assertSame(0, $code, Artisan::output());
            $this->assertFileExists($target);

            $loaded = json_decode((string) file_get_contents($target), true);
            $this->assertSame('yat', $loaded['sets']['pre_test'][0]['slp1']);
            $this->assertFalse(config('features.rq4_study'));
        } finally {
            if ($backup !== null) {
                file_put_contents($target, $backup);
            } elseif (is_file($target) && str_contains($exportDir, 'sg_pedagogy_export_')) {
                // leave production bank alone if we overwrote from fixture — restore if backup existed
            }
            @unlink($exportDir.DIRECTORY_SEPARATOR.'export_manifest.json');
            @unlink($exportDir.DIRECTORY_SEPARATOR.'rq4_item_bank.json');
            @rmdir($exportDir);
        }
    }

    public function test_schema_major_mismatch_fails(): void
    {
        $exportDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sg_pedagogy_export_bad_'.uniqid();
        mkdir($exportDir);
        file_put_contents(
            $exportDir.DIRECTORY_SEPARATOR.'export_manifest.json',
            json_encode(['schema_version' => '0.9.0', 'feeds' => []])
        );

        try {
            $code = Artisan::call('pedagogy:sync-sg-export', [
                '--path' => $exportDir,
                '--min-major' => 1,
            ]);
            $this->assertSame(1, $code);
        } finally {
            @unlink($exportDir.DIRECTORY_SEPARATOR.'export_manifest.json');
            @rmdir($exportDir);
        }
    }
}
