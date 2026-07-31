<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseSlugAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Массовое укорачивание courses.slug по карте old→new.
 * Старый slug пишется в course_slug_aliases (301).
 *
 *   php artisan courses:shorten-slugs path/to/map.tsv --dry-run
 *   php artisan courses:shorten-slugs path/to/map.tsv --apply
 *
 * Формат map: TSV/CSV, по строке `old_slug<TAB|comma|space>new_slug`, # — комментарий.
 * Пример строки: grammatika-xindi-gr-2-subbota-1300-2026	hindi-2_sb1300-2026
 */
class ShortenCourseSlugs extends Command
{
    protected $signature = 'courses:shorten-slugs
                            {map : Путь к TSV/CSV карте old→new}
                            {--dry-run : Только отчёт, без записи}
                            {--apply : Применить изменения}';

    protected $description = 'Укоротить slug курсов по карте; старые → course_slug_aliases (301)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Укажите ровно один флаг: --dry-run или --apply.');

            return self::FAILURE;
        }

        $path = $this->argument('map');
        if (! is_readable($path)) {
            $this->error("Файл не читается: {$path}");

            return self::FAILURE;
        }

        $pairs = $this->parseMap((string) file_get_contents($path));
        if ($pairs === []) {
            $this->warn('Карта пуста.');

            return self::SUCCESS;
        }

        $errors = [];
        $plan = [];

        foreach ($pairs as $old => $new) {
            if ($old === $new) {
                $errors[] = "{$old}: old == new, пропуск";

                continue;
            }

            $course = Course::query()->where('slug', $old)->first()
                ?? Course::resolveBySlug($old);

            if ($course === null) {
                $errors[] = "{$old}: курс не найден";

                continue;
            }

            if ($course->slug === $new) {
                $this->line("OK (уже): {$old} → {$new} (id={$course->id})");

                continue;
            }

            $collision = Course::query()
                ->where('slug', $new)
                ->where('id', '!=', $course->id)
                ->exists();
            if ($collision) {
                $errors[] = "{$old} → {$new}: new уже занят другим курсом";

                continue;
            }

            $aliasCollision = CourseSlugAlias::query()
                ->where('slug', $new)
                ->where('course_id', '!=', $course->id)
                ->exists();
            if ($aliasCollision) {
                $errors[] = "{$old} → {$new}: new занят алиасом другого курса";

                continue;
            }

            $plan[] = [$course, $old, $new];
            $this->line(($apply ? 'APPLY' : 'PLAN').": id={$course->id} {$course->slug} → {$new} (alias ← {$course->slug})");
        }

        if ($errors !== []) {
            $this->newLine();
            $this->error('Ошибки карты:');
            foreach ($errors as $e) {
                $this->line('  - '.$e);
            }

            return self::FAILURE;
        }

        if ($plan === [] || $dryRun) {
            $this->info($dryRun ? 'Dry-run завершён, записей нет.' : 'Нечего применять.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan as [$course, $old, $new]) {
                /** @var Course $course */
                // Observer на updated сам сложит предыдущий slug в aliases.
                $course->slug = $new;
                $course->save();

                // Если в карте old был уже alias (не канон) — канон сменился
                // с course.slug, alias old мог остаться; ensure old → alias.
                CourseSlugAlias::query()->firstOrCreate(
                    ['slug' => $old],
                    ['course_id' => $course->id, 'created_at' => now()],
                );
            }
        });

        $this->info('Применено пар: '.count($plan));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> old => new
     */
    private function parseMap(string $raw): array
    {
        $pairs = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^([^\s,;]+)[\s,;]+([^\s,;]+)\s*$/u', $line, $m)) {
                $this->warn("Пропуск строки (не распознана): {$line}");

                continue;
            }
            $pairs[$m[1]] = $m[2];
        }

        return $pairs;
    }
}
