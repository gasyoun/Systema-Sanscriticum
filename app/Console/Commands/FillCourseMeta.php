<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H2893 — fill courses.meta_title / meta_description from a reviewable CSV.
 *
 *   php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv --dry-run
 *   php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv
 *   php artisan seo:fill-course-meta --reset-slugs=a,b --dry-run
 *
 * Updates only the two meta columns. Never touches title, prices, visibility, tariffs.
 */
class FillCourseMeta extends Command
{
    private const TITLE_MAX = 60;

    private const DESC_MAX = 160;

    protected $signature = 'seo:fill-course-meta
        {csv? : Path to slug,meta_title,meta_description CSV (UTF-8, no BOM)}
        {--dry-run : Print a table and write nothing}
        {--reset-slugs= : Comma-separated slugs whose meta columns are set NULL}';

    protected $description = 'Fill or reset course meta_title / meta_description from a CSV';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $resetRaw = (string) $this->option('reset-slugs');
        $csvPath = $this->argument('csv');

        if (($csvPath === null || $csvPath === '') && $resetRaw === '') {
            $this->error('Pass a CSV path and/or --reset-slugs=.');

            return self::FAILURE;
        }

        if ($resetRaw !== '') {
            $resetStatus = $this->resetSlugs($resetRaw, $dry);
            if ($resetStatus !== self::SUCCESS) {
                return $resetStatus;
            }
            if ($csvPath === null || $csvPath === '') {
                return self::SUCCESS;
            }
        }

        return $this->applyCsv((string) $csvPath, $dry);
    }

    private function resetSlugs(string $resetRaw, bool $dry): int
    {
        $slugs = array_values(array_filter(array_map('trim', explode(',', $resetRaw)), fn ($s) => $s !== ''));
        if ($slugs === []) {
            $this->error('--reset-slugs= is empty.');

            return self::FAILURE;
        }

        $found = Course::query()->whereIn('slug', $slugs)->get(['id', 'slug', 'title']);
        $foundSlugs = $found->pluck('slug')->all();
        $missing = array_values(array_diff($slugs, $foundSlugs));
        if ($missing !== []) {
            $this->error('Reset slug(s) not found: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->table(['slug', 'action'], array_map(fn ($s) => [$s, $dry ? 'reset-preview' : 'reset'], $slugs));

        if ($dry) {
            $this->warn('Dry-run — no DB write.');

            return self::SUCCESS;
        }

        Course::query()->whereIn('slug', $slugs)->update([
            'meta_title' => null,
            'meta_description' => null,
        ]);
        $this->info('Reset meta columns for '.count($slugs).' slug(s).');

        return self::SUCCESS;
    }

    private function applyCsv(string $csvPath, bool $dry): int
    {
        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            $this->error("CSV not found or unreadable: {$csvPath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($csvPath);
        if ($raw === false) {
            $this->error("Could not read CSV: {$csvPath}");

            return self::FAILURE;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $this->error('CSV must be UTF-8 without BOM.');

            return self::FAILURE;
        }

        $parsed = $this->parseCsv($raw);
        if ($parsed['errors'] !== []) {
            foreach ($parsed['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $rows = $parsed['rows'];
        $slugs = array_column($rows, 'slug');

        $courses = Course::query()
            ->whereIn('slug', $slugs)
            ->get(['id', 'slug', 'title', 'is_visible', 'meta_title', 'meta_description'])
            ->keyBy('slug');

        $errors = [];
        $table = [];
        $writes = [];

        foreach ($rows as $row) {
            $course = $courses->get($row['slug']);
            if ($course === null || ! $course->is_visible) {
                $errors[] = "Slug is not a visible course: {$row['slug']}";
                $table[] = [$row['slug'], 'conflict', $row['meta_title']];

                continue;
            }

            $same = $course->meta_title === $row['meta_title']
                && $course->meta_description === $row['meta_description'];
            if ($same) {
                $table[] = [$row['slug'], 'skip-unchanged', $row['meta_title']];

                continue;
            }

            $table[] = [$row['slug'], 'write', $row['meta_title']];
            $writes[] = ['id' => $course->id, 'row' => $row];
        }

        $this->table(['slug', 'action', 'meta_title'], $table);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($dry) {
            $this->warn('Dry-run — no DB write.');
            $this->info(sprintf('%d write / %d skip-unchanged.', count($writes), count($rows) - count($writes)));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($writes): void {
            foreach ($writes as $item) {
                Course::query()->whereKey($item['id'])->update([
                    'meta_title' => $item['row']['meta_title'],
                    'meta_description' => $item['row']['meta_description'],
                ]);
            }
        });

        $this->info(sprintf('Applied %d meta update(s).', count($writes)));

        return self::SUCCESS;
    }

    /**
     * @return array{rows: list<array{slug: string, meta_title: string, meta_description: string}>, errors: list<string>}
     */
    private function parseCsv(string $raw): array
    {
        $errors = [];
        $rows = [];
        $seenSlugs = [];
        $seenTitles = [];
        $seenDescs = [];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['rows' => [], 'errors' => ['Could not open temp stream for CSV.']];
        }
        fwrite($handle, $raw);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return ['rows' => [], 'errors' => ['CSV is empty.']];
        }
        $header = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), $header);
        $need = ['slug', 'meta_title', 'meta_description'];
        foreach ($need as $col) {
            if (! in_array($col, $header, true)) {
                $errors[] = "CSV missing column: {$col}";
            }
        }
        if ($errors !== []) {
            fclose($handle);

            return ['rows' => [], 'errors' => $errors];
        }

        $idx = array_flip($header);
        $line = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($cols === [null] || $cols === false) {
                continue;
            }
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $slug = trim((string) ($cols[$idx['slug']] ?? ''));
            $title = trim((string) ($cols[$idx['meta_title']] ?? ''));
            $desc = trim((string) ($cols[$idx['meta_description']] ?? ''));
            if ($slug === '' || $title === '' || $desc === '') {
                $errors[] = "Line {$line}: empty slug, title, or description.";

                continue;
            }
            if (isset($seenSlugs[$slug])) {
                $errors[] = "Duplicate slug in CSV: {$slug}";
            }
            if (isset($seenTitles[$title])) {
                $errors[] = "Duplicate meta_title in CSV: {$title}";
            }
            if (isset($seenDescs[$desc])) {
                $errors[] = "Duplicate meta_description in CSV: {$desc}";
            }
            $titleLen = mb_strlen($title);
            $descLen = mb_strlen($desc);
            if ($titleLen > self::TITLE_MAX) {
                $errors[] = "Line {$line} ({$slug}): meta_title {$titleLen} > ".self::TITLE_MAX;
            }
            if ($descLen > self::DESC_MAX) {
                $errors[] = "Line {$line} ({$slug}): meta_description {$descLen} > ".self::DESC_MAX;
            }
            $seenSlugs[$slug] = true;
            $seenTitles[$title] = true;
            $seenDescs[$desc] = true;
            $rows[] = [
                'slug' => $slug,
                'meta_title' => $title,
                'meta_description' => $desc,
            ];
        }
        fclose($handle);

        if ($rows === [] && $errors === []) {
            $errors[] = 'CSV has no data rows.';
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}
