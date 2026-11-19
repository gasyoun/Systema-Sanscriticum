<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourseNamingStandard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only аудит названий против единого стандарта (19-11-2026).
 *
 * Проверяет группы (groups.name), расписания (schedules.title) и уроки
 * (lessons.title); у расписаний/уроков титул режется до базы — тег «(#N, dd.mm.yy)»
 * добавляет генератор, это не часть названия потока. Мягкоудалённые строки
 * расписаний не смотрим: студентам они не видны.
 *
 * Exit 1 при любом нарушении — можно ставить в gate запуска новой группы
 * (см. /new-group: перед созданием группы и серии прогнать этот аудит).
 * НИЧЕГО не переименовывает: исторические строки — решение человека.
 */
class CheckCourseNaming extends Command
{
    protected $signature = 'catalog:check-naming';

    protected $description = 'Read-only проверка названий (группы, расписания, уроки) против единого стандарта; exit 1 при нарушениях.';

    public function handle(): int
    {
        $total = 0;

        foreach (DB::table('groups')->select('id', 'name')->orderBy('id')->get() as $group) {
            foreach (CourseNamingStandard::violations((string) $group->name) as $violation) {
                $this->line(sprintf('groups #%d «%s» — %s', $group->id, $group->name, $violation['message']));
                $total++;
            }
        }

        foreach (DB::table('schedules')->select('id', 'title')->whereNull('deleted_at')->orderBy('id')->get() as $schedule) {
            $base = CourseNamingStandard::stripLessonTag((string) $schedule->title);
            foreach (CourseNamingStandard::violations($base) as $violation) {
                $this->line(sprintf('schedules #%d «%s» — %s', $schedule->id, $schedule->title, $violation['message']));
                $total++;
            }
        }

        foreach (DB::table('lessons')->select('id', 'title')->orderBy('id')->get() as $lesson) {
            $base = CourseNamingStandard::stripLessonTag((string) $lesson->title);
            foreach (CourseNamingStandard::violations($base) as $violation) {
                $this->line(sprintf('lessons #%d «%s» — %s', $lesson->id, $lesson->title, $violation['message']));
                $total++;
            }
        }

        if ($total === 0) {
            $this->info('Нарушений стандарта названий нет.');

            return self::SUCCESS;
        }

        $this->warn("Нарушений стандарта названий: {$total}. Исторические строки не переименовываются автоматически — правка по решению человека.");

        return self::FAILURE;
    }
}
