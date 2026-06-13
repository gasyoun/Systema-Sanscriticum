<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\CourseNameResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Заводит скрытыми исторические курсы, которых нет в выгрузке админки, но по которым
 * есть реальные оплаты в мастер-таблице (например, старые группы Кочергиной гр.35,
 * Бюллер гр.26 и т.п.). Список берётся из course_aliases.csv (action = create_hidden)
 * через CourseNameResolver.
 *
 *   php artisan courses:seed-historical
 *   php artisan courses:seed-historical --dry-run
 *
 * Курсы создаются с is_visible=false и is_active=false (не на витрине, не в ЛК),
 * чтобы они служили только «корзиной» для исторических оплат.
 */
class SeedHistoricalCourses extends Command
{
    protected $signature = 'courses:seed-historical
                            {--dry-run : Прогнать без записи в БД}';

    protected $description = 'Создание скрытых исторических курсов для импорта оплат, которых нет в админке';

    public function handle(CourseNameResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Режим: dry-run (без записи в БД)' : 'Режим: реальное создание');

        $names = $resolver->historicalCourses();
        if ($names === []) {
            $this->warn('Список исторических курсов пуст (проверь database/data/course_aliases.csv).');

            return self::SUCCESS;
        }

        $created = 0;
        $existed = 0;

        foreach ($names as $name) {
            $existing = Course::where('title', $name)->first();
            if ($existing !== null) {
                $existed++;

                continue;
            }

            if (! $dryRun) {
                Course::create([
                    'title' => $name,
                    'slug' => Str::slug($name) ?: Str::random(10),
                    'is_visible' => false,
                    'is_active' => false,
                ]);
            }
            $this->line("  • создан скрытый курс: {$name}");
            $created++;
        }

        $this->newLine();
        $this->table(
            ['Действие', 'Кол-во'],
            [
                ['создано', $created],
                ['уже существовали', $existed],
                ['всего в списке', count($names)],
            ],
        );

        if ($dryRun) {
            $this->warn('🔍 dry-run — БД не изменена.');
        }

        return self::SUCCESS;
    }
}
