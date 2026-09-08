<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;

/**
 * MG 08-09: в подтверждении отмены — РОВНО ОДНА главная причина
 * («отпуск преподавателя», «государственные каникулы», «отсутствие кворума»).
 *
 * Приоритет: названная в команде (свободный текст после «:»/«—») →
 * отпускное окно преподавателя (H4253) → групповые каникулы (H3790) →
 * причины нет (заголовок без скобок).
 */
final class CancelReasonResolver
{
    public const VACATION_TEACHER = 'отпуск преподавателя';

    public const VACATION_GROUP = 'каникулы';

    public static function resolve(Schedule $schedule, ?string $stated = null): ?string
    {
        $stated = self::sanitize($stated);
        if ($stated !== null) {
            return $stated;
        }

        $start = $schedule->start;
        if ($start === null) {
            return null;
        }

        $course = $schedule->course_id !== null ? Course::find($schedule->course_id) : null;
        if ($course !== null && $course->allTeachers()->contains(
            fn ($teacher): bool => $teacher->isOnVacationOn($start),
        )) {
            return self::VACATION_TEACHER;
        }

        $group = $schedule->group_id !== null ? Group::find($schedule->group_id) : null;
        if ($group !== null && (bool) $group->is_on_vacation) {
            return self::VACATION_GROUP;
        }

        return null;
    }

    /**
     * Причина уйдёт в HTML-сообщение Telegram — режем разметку и длину.
     */
    public static function sanitize(?string $stated): ?string
    {
        $stated = trim((string) preg_replace('/\s+/u', ' ', (string) $stated));
        $stated = trim(str_replace(['<', '>'], '', $stated));

        if ($stated === '') {
            return null;
        }

        return mb_substr($stated, 0, 80);
    }
}
