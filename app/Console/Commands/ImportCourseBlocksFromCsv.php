<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Support\CourseNameResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCourseBlocksFromCsv extends Command
{
    /**
     * Сигнатура:
     *   php artisan blocks:import-csv "path/to/file.csv"
     *   php artisan blocks:import-csv "path/to/file.csv" --dry-run
     *
     * Ожидаемый формат CSV (заголовок обязателен):
     *   ID,Название курса,Блок,Начало,Конец,...
     * Даты в формате DD.MM.YYYY. Разделитель — запятая, кавычки — двойные.
     */
    protected $signature = 'blocks:import-csv
                            {path : Путь к CSV-файлу}
                            {--dry-run : Прогнать без записи в БД}';

    protected $description = 'Импорт сроков блоков курсов из CSV в course_blocks';

    /** @var array<string, ?Course> Кеш курсов по title (null = не найден). */
    private array $courseCache = [];

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

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('Пустой CSV.');

            return self::FAILURE;
        }
        $this->stripBom($header);

        $this->info($dryRun ? 'Режим: dry-run (без записи в БД)' : 'Режим: реальный импорт');
        $this->newLine();

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped_already_filled' => 0,
        ];
        $missingCourses = [];   // title => count
        $emptyDates = [];       // ["<title> #<number>", ...]
        $badDates = [];         // ["row <n>: <reason>", ...]

        DB::beginTransaction();

        $rowNum = 1; // header
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if ($this->isBlankRow($row)) {
                continue;
            }

            // Колонки: 0=ID, 1=Название курса, 2=Блок, 3=Начало, 4=Конец
            $title = trim((string) ($row[1] ?? ''));
            $numberRaw = trim((string) ($row[2] ?? ''));
            $startsRaw = trim((string) ($row[3] ?? ''));
            $endsRaw = trim((string) ($row[4] ?? ''));

            if ($title === '' || $numberRaw === '') {
                continue;
            }

            $number = (int) $numberRaw;
            if ($number <= 0) {
                $badDates[] = "row {$rowNum}: некорректный номер блока '{$numberRaw}'";

                continue;
            }

            if ($startsRaw === '' || $endsRaw === '') {
                $emptyDates[] = "{$title} #{$number}";

                continue;
            }

            $course = $this->resolveCourse($title);
            if ($course === null) {
                $missingCourses[$title] = ($missingCourses[$title] ?? 0) + 1;

                continue;
            }

            $startsAt = $this->parseDate($startsRaw, true);
            $endsAt = $this->parseDate($endsRaw, false);
            if ($startsAt === null || $endsAt === null) {
                $badDates[] = "row {$rowNum}: не удалось распарсить даты '{$startsRaw}' / '{$endsRaw}'";

                continue;
            }

            $existing = CourseBlock::query()
                ->where('course_id', $course->id)
                ->where('number', $number)
                ->first();

            if ($existing === null) {
                if (! $dryRun) {
                    CourseBlock::create([
                        'course_id' => $course->id,
                        'number' => $number,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                }
                $stats['created']++;

                continue;
            }

            if ($existing->starts_at === null || $existing->ends_at === null) {
                if (! $dryRun) {
                    $existing->update([
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                }
                $stats['updated']++;

                continue;
            }

            $stats['skipped_already_filled']++;
        }

        fclose($handle);

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->renderSummary($stats, $missingCourses, $emptyDates, $badDates, $dryRun);

        return self::SUCCESS;
    }

    private function resolveCourse(string $title): ?Course
    {
        // Приводим название к каноническому (из админки) перед поиском курса.
        $title = app(CourseNameResolver::class)->resolve($title);

        if (array_key_exists($title, $this->courseCache)) {
            return $this->courseCache[$title];
        }

        $course = Course::where('title', $title)->first();

        return $this->courseCache[$title] = $course;
    }

    private function parseDate(string $value, bool $startOfDay): ?Carbon
    {
        $carbon = Carbon::createFromFormat('d.m.Y', $value);
        if ($carbon === false) {
            return null;
        }

        return $startOfDay ? $carbon->startOfDay() : $carbon->endOfDay();
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $header
     */
    private function stripBom(array &$header): void
    {
        if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr($header[0], 3);
        }
    }

    /**
     * @param  array{created:int, updated:int, skipped_already_filled:int}  $stats
     * @param  array<string,int>  $missingCourses
     * @param  array<int,string>  $emptyDates
     * @param  array<int,string>  $badDates
     */
    private function renderSummary(
        array $stats,
        array $missingCourses,
        array $emptyDates,
        array $badDates,
        bool $dryRun,
    ): void {
        $suffix = $dryRun ? ' (dry-run)' : '';

        $this->newLine();
        $this->line('<fg=green>Сводка'.$suffix.'</>');
        $this->table(
            ['Действие', 'Кол-во'],
            [
                ['created', $stats['created']],
                ['updated (пустые даты заполнены)', $stats['updated']],
                ['skipped: already has dates', $stats['skipped_already_filled']],
                ['skipped: course not found', array_sum($missingCourses)],
                ['skipped: empty dates', count($emptyDates)],
                ['skipped: bad data', count($badDates)],
            ],
        );

        if (! empty($missingCourses)) {
            $this->newLine();
            $this->line('<fg=yellow>Курсы, не найденные по title (title → строк в CSV):</>');
            ksort($missingCourses);
            foreach ($missingCourses as $title => $count) {
                $this->line("  • {$title} — {$count}");
            }
        }

        if (! empty($emptyDates)) {
            $this->newLine();
            $this->line('<fg=yellow>Пропущено из-за пустых дат:</>');
            foreach ($emptyDates as $line) {
                $this->line("  • {$line}");
            }
        }

        if (! empty($badDates)) {
            $this->newLine();
            $this->line('<fg=red>Битые строки:</>');
            foreach ($badDates as $line) {
                $this->line("  • {$line}");
            }
        }
    }
}
