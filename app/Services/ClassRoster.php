<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Ожидаемый ростер» занятия — единый источник правды о том, кто должен быть на
 * занятии. Привязка к группе (приоритет) либо к курсу через pivot course_group.
 * Используется и напоминаниями, и учётом посещаемости («ожидали vs пришли»).
 */
class ClassRoster
{
    /**
     * Запрос ожидаемых студентов занятия (без фильтра по мессенджерам).
     * null — если занятие не привязано ни к группе, ни к курсу (адресной аудитории нет).
     */
    public static function query(Schedule $schedule): ?Builder
    {
        if ($schedule->group_id) {
            return User::query()->whereHas('groups', fn (Builder $q) => $q->where('groups.id', $schedule->group_id));
        }

        if ($schedule->course_id) {
            // Студенты курса — через группы (pivot course_group, см. Group::courses()).
            return User::query()->whereHas('groups.courses', fn (Builder $q) => $q->where('courses.id', $schedule->course_id));
        }

        return null;
    }
}
