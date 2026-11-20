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
    protected $signature = 'catalog:check-naming {--group= : ID группы: проверять только её строки (гейт запуска группы), без аргумента — полный цензус по всем таблицам}';

    protected $description = 'Read-only проверка названий (группы, расписания, уроки) против единого стандарта; exit 1 при нарушениях.';

    public function handle(): int
    {
        $total = 0;

        $groupId = $this->option('group') !== null ? (int) $this->option('group') : null;

        // Скоуп группы: гейт запуска новой группы должен краснеть ТОЛЬКО на её
        // строках — постоянный красный от исторических имён приучает игнорировать
        // красное (runbook §2: перед генерацией серии прогоняют с --group=<ID>).
        // Без --group — полный цензус: исторические строки перечисляются для человека.

        $groups = $groupId !== null
            ? DB::table('groups')->select('id', 'name')->where('id', $groupId)->get()
            : DB::table('groups')->select('id', 'name')->orderBy('id')->get();

        foreach ($groups as $group) {
            foreach (CourseNamingStandard::violations((string) $group->name) as $violation) {
                $this->line(sprintf('groups #%d «%s» — %s', $group->id, $group->name, $violation['message']));
                $total++;
            }
        }

        $schedules = DB::table('schedules')->select('id', 'title', 'group_id')->whereNull('deleted_at');
        if ($groupId !== null) {
            $schedules->where('group_id', $groupId);
        }
        foreach ($schedules->orderBy('id')->get() as $schedule) {
            $base = CourseNamingStandard::stripLessonTag((string) $schedule->title);
            foreach (CourseNamingStandard::violations($base) as $violation) {
                $this->line(sprintf('schedules #%d «%s» — %s', $schedule->id, $schedule->title, $violation['message']));
                $total++;
            }
        }

        $lessons = DB::table('lessons')->select('id', 'title', 'group_id', 'course_id');
        if ($groupId !== null) {
            // Уроки-записи наследуют титул занятия; скоуп группы — по связке
            // lessons.group_id ИЛИ по курсам группы (course_group pivot): у записей
            // потоков КШ group_id пуст, курс привязан через pivot.
            $courseIds = DB::table('course_group')->where('group_id', $groupId)->pluck('course_id');
            $lessons->where(static function ($query) use ($groupId, $courseIds): void {
                $query->where('group_id', $groupId);
                if ($courseIds->isNotEmpty()) {
                    $query->orWhereIn('course_id', $courseIds);
                }
            });
        }
        foreach ($lessons->orderBy('id')->get() as $lesson) {
            $base = CourseNamingStandard::stripLessonTag((string) $lesson->title);
            foreach (CourseNamingStandard::violations($base) as $violation) {
                $this->line(sprintf('lessons #%d «%s» — %s', $lesson->id, $lesson->title, $violation['message']));
                $total++;
            }
        }

        if ($total === 0) {
            $this->info($groupId !== null
                ? "Нарушений стандарта названий в строках группы #{$groupId} нет."
                : 'Нарушений стандарта названий нет.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Нарушений стандарта названий: %d%s. Исторические строки не переименовываются автоматически — правка по решению человека.',
            $total,
            $groupId !== null ? " (в строках группы #{$groupId})" : '',
        ));

        return self::FAILURE;
    }
}
