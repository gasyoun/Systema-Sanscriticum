<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Teacher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H4253: отпускное покрытие на уровне ПРЕПОДАВАТЕЛЯ (в отличие от
 * группового флага is_on_vacation из H3790). Группа считается отпускной,
 * если дату покрывает окно любого преподавателя любого её курса —
 * основного (course.teacher_id) или со-препода (pivot course_teacher).
 *
 * Единая точка правды для трёх потребителей: публичный фид (аннотация
 * «выход из каникул»), подавление напоминаний zapisi-бота и админка.
 */
final class TeacherVacation
{
    /**
     * Покрывает ли отпуск кого-то из преподавателей группы дату занятия.
     */
    public static function covers(Group $group, CarbonInterface $date): bool
    {
        foreach (self::teachersOf($group) as $teacher) {
            if ($teacher->isOnVacationOn($date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Дата выхода из каникул, если покрытие есть и дата выхода известна.
     * null — группа не покрыта ИЛИ отпуск бессрочный («уточняется»).
     */
    public static function resumeDate(Group $group, CarbonInterface $date): ?CarbonInterface
    {
        foreach (self::teachersOf($group) as $teacher) {
            if ($teacher->isOnVacationOn($date)) {
                return $teacher->on_vacation_until !== null
                    ? Carbon::parse($teacher->on_vacation_until->toDateString())
                    : null;
            }
        }

        return null;
    }

    /**
     * Все преподаватели курсов группы. Группировка по id — со-препод мог быть
     * уже загружен вместе с основным; дубли в окнах не мешают, но в цикле
     * фида лишние итерации ни к чему.
     *
     * @return Collection<int, Teacher>
     */
    public static function teachersOf(Group $group): Collection
    {
        return $group->courses
            ->flatMap(fn ($course) => $course->allTeachers())
            ->unique('id')
            ->values();
    }
}
