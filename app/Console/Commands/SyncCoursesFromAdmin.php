<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Синхронизирует канонический список курсов из выгрузки админки.
 *
 * Формат CSV (заголовок обязателен): ID,Название,URL (slug)
 *   php artisan courses:sync-from-admin storage/app/imports/courses_admin.csv
 *   php artisan courses:sync-from-admin <path> --dry-run
 *
 * Сопоставление идёт по slug (он уникален и стабилен), title приводится к
 * каноническому. Это делает Course.title эталонным — чтобы импорт оплат/блоков,
 * который ищет курс по точному title, находил совпадения.
 */
class SyncCoursesFromAdmin extends Command
{
    protected $signature = 'courses:sync-from-admin
                            {path : Путь к CSV-выгрузке курсов из админки}
                            {--dry-run : Прогнать без записи в БД}';

    protected $description = 'Синхронизация канонических названий курсов (по slug) из выгрузки админки';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Не удалось открыть файл: {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            $this->error('Пустой CSV.');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Режим: dry-run (без записи в БД)' : 'Режим: реальная синхронизация');

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $rowNum = 1;

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rowNum++;

            // Колонки: 0=ID, 1=Название, 2=slug
            $title = isset($row[1]) ? trim((string) $row[1]) : '';
            $slug = isset($row[2]) ? trim((string) $row[2]) : '';

            // Снимаем BOM с первой строки данных, если выгрузка с BOM.
            $title = ltrim($title, "\xEF\xBB\xBF");

            if ($title === '') {
                $skipped++;

                continue;
            }

            if ($slug === '') {
                $slug = Str::slug($title) ?: Str::random(10);
            }

            $existing = Course::where('slug', $slug)->first();

            if ($existing === null) {
                if (! $dryRun) {
                    Course::create([
                        'title' => $title,
                        'slug' => $slug,
                        'is_visible' => true,
                    ]);
                }
                $created++;

                continue;
            }

            if ($existing->title !== $title) {
                if (! $dryRun) {
                    $existing->update(['title' => $title]);
                }
                $this->line("  • {$slug}: '{$existing->title}' → '{$title}'");
                $updated++;
            } else {
                $unchanged++;
            }
        }

        fclose($handle);

        $this->newLine();
        $this->table(
            ['Действие', 'Кол-во'],
            [
                ['created', $created],
                ['updated (title исправлен)', $updated],
                ['unchanged', $unchanged],
                ['skipped (пустые)', $skipped],
            ],
        );

        if ($dryRun) {
            $this->warn('🔍 dry-run — БД не изменена.');
        }

        return self::SUCCESS;
    }
}
