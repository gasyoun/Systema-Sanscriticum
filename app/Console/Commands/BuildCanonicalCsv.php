<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourseNameResolver;
use Illuminate\Console\Command;

/**
 * Вспомогательная команда «для проверки глазами»: берёт CSV (по умолчанию payments.csv),
 * прогоняет колонку с названием курса через CourseNameResolver и пишет рядом
 * <имя>_canonical.csv с уже подставленными каноническими названиями, а также печатает
 * отчёт «старое название → новое (строк)».
 *
 *   php artisan import:build-canonical
 *   php artisan import:build-canonical --file=blocks.csv --course-col=1
 *
 * Импорт оплат/блоков делает ту же нормализацию на лету, поэтому эта команда нужна
 * только для ревью соответствий и подготовки «чистых» входных файлов.
 */
class BuildCanonicalCsv extends Command
{
    protected $signature = 'import:build-canonical
                            {--file=payments.csv : Имя файла в storage/app/imports}
                            {--course-col=2 : Индекс колонки с названием курса (0-based)}';

    protected $description = 'Сгенерировать копию CSV с каноническими названиями курсов + отчёт изменений';

    public function handle(CourseNameResolver $resolver): int
    {
        $file = (string) $this->option('file');
        $col = (int) $this->option('course-col');
        $src = storage_path('app/imports/'.$file);

        if (! is_file($src)) {
            $this->error("Файл не найден: {$src}");

            return self::FAILURE;
        }

        $dst = preg_replace('/\.csv$/i', '', $src).'_canonical.csv';

        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if ($in === false || $out === false) {
            $this->error('Не удалось открыть файлы.');

            return self::FAILURE;
        }

        $changes = [];   // "old → new" => count
        $firstRow = true;

        while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
            if ($firstRow) {
                $firstRow = false;
                fputcsv($out, $row, ',', '"', '');

                continue;
            }

            $original = isset($row[$col]) ? trim((string) $row[$col]) : '';
            if ($original !== '') {
                $canonical = $resolver->resolve($original);
                if ($canonical !== $original) {
                    $changes["{$original} → {$canonical}"] = ($changes["{$original} → {$canonical}"] ?? 0) + 1;
                    $row[$col] = $canonical;
                }
            }

            fputcsv($out, $row, ',', '"', '');
        }

        fclose($in);
        fclose($out);

        $this->info("Готово: {$dst}");
        $this->newLine();

        if ($changes === []) {
            $this->line('Изменений названий курсов не потребовалось.');

            return self::SUCCESS;
        }

        arsort($changes);
        $this->line('<fg=green>Применённые соответствия (старое → новое: строк):</>');
        foreach ($changes as $line => $count) {
            $this->line("  • {$line}  ({$count})");
        }
        $this->newLine();
        $this->info('Всего изменённых строк: '.array_sum($changes).', уникальных соответствий: '.count($changes));

        return self::SUCCESS;
    }
}
