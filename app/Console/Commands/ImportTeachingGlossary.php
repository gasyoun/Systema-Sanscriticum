<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeachingGlossaryCourse;
use App\Models\TeachingGlossaryTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Импорт зарегистрированного агрегата «преподавательский глоссарий»
 * (dataset `stenogrammy-teaching-glossary`, kosha manifest) в таблицы
 * поверхности тира Top.
 *
 * FENCE: команда принимает ТОЛЬКО уже агрегированный TSV (кириллическая
 * форма → SLP1 лемма, частота ≥ 20, курсовые локусы, без персональных
 * данных). Никаких сырых корпусов: агрегат построен так, что строка
 * приватного корпуса в него попасть не может — этот инвариант держится
 * на источнике данных, команда его не перепроверяет и перепроверять не
 * умеет. Файл в репозиторий не коммитится (датасет restricted): путь
 * передаётся аргументом, на проде — из storage/app.
 *
 * Идемпотентен: полный рефреш в транзакции; повторный запуск даёт те же
 * строки (рефреш-семантика честнее upsert для переименованных курсов).
 */
final class ImportTeachingGlossary extends Command
{
    protected $signature = 'teaching-glossary:import
                            {termsPath : Путь к TSV глоссария (cyrillic_form, lemma_slp1, ru_gloss, corpus_freq, n_files, n_courses, ambiguous_lemmas, gloss_provenance)}
                            {--courses-path= : Путь к TSV профиля по курсам (course, n_headwords, top_terms)}
                            {--dry-run : Посчитать и показать, ничего не писать}';

    protected $description = 'Импорт агрегата преподавательского глоссария в таблицы поверхности тира Top';

    public function handle(): int
    {
        $termsPath = (string) $this->argument('termsPath');

        if (! is_file($termsPath) || ! is_readable($termsPath)) {
            $this->error("Файл не читается: {$termsPath}");

            return self::FAILURE;
        }

        $handle = fopen($termsPath, 'rb');

        if ($handle === false) {
            $this->error("Файл не открывается: {$termsPath}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle, 0, "\t");

        $expected = ['cyrillic_form', 'lemma_slp1', 'ru_gloss', 'corpus_freq', 'n_files', 'n_courses', 'ambiguous_lemmas', 'gloss_provenance'];

        if ($header !== $expected) {
            fclose($handle);
            $this->error('Неожиданная шапка TSV: '.implode(' | ', (array) $header));

            return self::FAILURE;
        }

        $terms = [];
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) < 8) {
                $skipped++;

                continue;
            }

            [$form, $lemma, $gloss, $freq, $files, $courses, $ambiguous, $provenance] = array_map(
                static fn (string $value): string => trim($value),
                $row
            );

            if ($form === '' || $lemma === '' || ! ctype_digit($freq)) {
                $skipped++;

                continue;
            }

            $terms[] = [
                'cyrillic_form' => $form,
                'lemma_slp1' => $lemma,
                'ru_gloss' => $gloss === '' ? null : $gloss,
                'corpus_freq' => (int) $freq,
                'n_files' => ctype_digit($files) ? (int) $files : 0,
                'n_courses' => ctype_digit($courses) ? (int) $courses : 0,
                'ambiguous_lemmas' => $ambiguous === 'yes',
                'gloss_provenance' => $provenance === '' ? null : $provenance,
            ];
        }

        fclose($handle);

        $coursesPath = (string) $this->option('courses-path');
        $courses = [];

        if ($coursesPath !== '') {
            if (! is_file($coursesPath) || ! is_readable($coursesPath)) {
                $this->error("Файл профиля не читается: {$coursesPath}");

                return self::FAILURE;
            }

            $courseHandle = fopen($coursesPath, 'rb');

            if ($courseHandle === false) {
                $this->error("Файл профиля не открывается: {$coursesPath}");

                return self::FAILURE;
            }

            fgetcsv($courseHandle, 0, "\t"); // шапка

            while (($row = fgetcsv($courseHandle, 0, "\t")) !== false) {
                if (count($row) < 3 || trim($row[0]) === '') {
                    continue;
                }

                $courses[] = [
                    'course' => trim($row[0]),
                    'n_headwords' => ctype_digit(trim($row[1])) ? (int) trim($row[1]) : 0,
                    'top_terms' => trim($row[2]) === '' ? null : trim($row[2]),
                ];
            }

            fclose($courseHandle);
        }

        $this->info('Терминов к записи: '.count($terms)." (пропущено строк: {$skipped}); курсов: ".count($courses).'.');

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($terms, $courses): void {
            TeachingGlossaryTerm::query()->delete();
            TeachingGlossaryCourse::query()->delete();

            foreach (array_chunk($terms, 500) as $chunk) {
                TeachingGlossaryTerm::query()->insert($chunk);
            }

            foreach (array_chunk($courses, 100) as $chunk) {
                TeachingGlossaryCourse::query()->insert($chunk);
            }
        });

        $this->info('Импорт завершён: '.TeachingGlossaryTerm::query()->count().' терминов, '.TeachingGlossaryCourse::query()->count().' курсов.');

        return self::SUCCESS;
    }
}
