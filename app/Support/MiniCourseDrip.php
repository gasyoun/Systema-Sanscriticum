<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Дрип мини-курса «Подготовительная группа»: урок 1 открыт сразу после
 * записи, каждый следующий — через drip_days дней (config mini_courses).
 * Отсчёт — от даты записи студента (course_user.created_at).
 *
 * Сотрудники (admin-like) дрип не видят — им контент открыт для проверки.
 */
final class MiniCourseDrip
{
    public static function isMiniCourse(Course $course): bool
    {
        return $course->slug === (string) config('mini_courses.slug');
    }

    /** Дата записи студента на курс (якорь дрипа). */
    public static function enrolledAt(User $user, int $courseId): ?Carbon
    {
        $createdAt = \DB::table('course_user')
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->value('created_at');

        return $createdAt !== null ? Carbon::parse($createdAt) : null;
    }

    /** Номер урока в курсе по порядку sort_order (1-based). */
    public static function lessonIndex(Lesson $lesson, int $courseId): int
    {
        $ordered = Lesson::where('course_id', $courseId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        $index = $ordered->search($lesson->id);

        return $index === false ? 1 : ((int) $index) + 1;
    }

    /** Когда урок откроется студенту с якорем $enrolledAt. */
    public static function openAt(Carbon $enrolledAt, int $lessonIndex): Carbon
    {
        $days = max(0, (int) config('mini_courses.drip_days', 3));

        return $enrolledAt->copy()->addDays(($lessonIndex - 1) * $days);
    }

    /**
     * Урок закрыт дрипом для студента? Возвращает дату открытия или null
     * (открыт / не мини-курс / сотрудник).
     */
    public static function lockedAtFor(User $user, Course $course, Lesson $lesson): ?Carbon
    {
        if (! self::isMiniCourse($course) || $user->isAdminLike()) {
            return null;
        }

        $enrolledAt = self::enrolledAt($user, (int) $course->id);

        if ($enrolledAt === null) {
            return null;
        }

        $openAt = self::openAt($enrolledAt, self::lessonIndex($lesson, (int) $course->id));

        return now()->lt($openAt) ? $openAt : null;
    }

    /**
     * Карта «lesson_id => дата открытия» только для ЗАКРЫТЫХ уроков курса —
     * для чипов на странице курса.
     */
    public static function lockedMapFor(User $user, Course $course): Collection
    {
        if (! self::isMiniCourse($course) || $user->isAdminLike()) {
            return collect();
        }

        $enrolledAt = self::enrolledAt($user, (int) $course->id);

        if ($enrolledAt === null) {
            return collect();
        }

        $now = now();
        $days = max(0, (int) config('mini_courses.drip_days', 3));

        return Lesson::where('course_id', $course->id)
            ->orderBy('sort_order')
            ->get(['id', 'block_number'])
            ->mapWithKeys(function (Lesson $lesson, int $i) use ($enrolledAt, $now, $days): array {
                $openAt = $enrolledAt->copy()->addDays($i * $days);

                return $now->lt($openAt)
                    ? [$lesson->id => $openAt]
                    : [];
            });
    }
}
