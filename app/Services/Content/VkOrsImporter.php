<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Read IndologyScholars vk-ors derived CSVs (not the raw xlsx).
 * Used by content:import-vk-ors and content:seed-month (H1564).
 *
 * @phpstan-type CsvRow array<string, string>
 */
final class VkOrsImporter
{
    /**
     * Resolve data directory: explicit path, config, storage/app/vk_ors, or fixtures.
     */
    public static function resolvePath(?string $path = null): string
    {
        if ($path !== null && $path !== '') {
            return rtrim($path, '\\/');
        }

        $configured = (string) config('content.vk_ors_data_path', '');
        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '\\/');
        }

        $storage = storage_path('app/vk_ors');
        if (is_dir($storage) && File::exists($storage.DIRECTORY_SEPARATOR.'activity_by_month.csv')) {
            return $storage;
        }

        $fixtures = base_path('tests/fixtures/vk_ors');
        if (is_dir($fixtures)) {
            return $fixtures;
        }

        throw new RuntimeException(
            'vk-ors data path not found. Pass --path= or set VK_ORS_DATA_PATH / copy CSVs to storage/app/vk_ors.'
        );
    }

    /**
     * @return list<CsvRow>
     */
    public function readCsv(string $dir, string $filename): array
    {
        $full = $dir.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($full)) {
            return [];
        }

        $handle = fopen($full, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$full}");
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === [null] || $header === []) {
                return [];
            }
            $header = array_map(static fn ($h) => trim((string) $h), $header);

            $rows = [];
            while (($data = fgetcsv($handle)) !== false) {
                if ($data === [null] || $data === []) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $key) {
                    $row[$key] = isset($data[$i]) ? (string) $data[$i] : '';
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Posts-per-month for a calendar month key "MM" averaged across years, or 0.
     *
     * @param  list<CsvRow>  $activityByMonth
     */
    public function historicalPostsForMonth(array $activityByMonth, int $month): int
    {
        $counts = [];
        foreach ($activityByMonth as $row) {
            $ym = $row['ym'] ?? '';
            if (! preg_match('/^\d{4}-(\d{2})$/', $ym, $m)) {
                continue;
            }
            if ((int) $m[1] !== $month) {
                continue;
            }
            $counts[] = (int) ($row['posts'] ?? 0);
        }

        if ($counts === []) {
            return 0;
        }

        return (int) round(array_sum($counts) / count($counts));
    }
}
