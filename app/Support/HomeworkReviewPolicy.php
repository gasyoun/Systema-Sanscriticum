<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HomeworkSubmission;
use App\Models\Lesson;

/**
 * Проверяется ли ДЗ курса преподавателем (H3081, MG 18-08-2026).
 *
 * «Продленка с домашкой, но без проверки домашки от преподавателя, как и
 * напевный санскрит.»
 *
 * Важно, чем это отличается от «у группы нет проверяющего». Курс без строк в
 * `group_reviewer` НЕ является непроверяемым: `HomeworkNotifier::submitted()`
 * в этом случае шлёт письмо преподавателю курса на каждую работу, то есть
 * пустой `group_reviewer` читается как «проверяет преподаватель лично».
 * Здесь нужен третий режим — «не проверяет никто»: работа принимается,
 * хранится и видна в админке, но не порождает ни одного уведомления и не
 * обещает студенту проверки.
 *
 * Правило привязано к ФОРМАТУ курса (префикс слага), а не к конкретному
 * потоку: следующий «Напевный санскрит — гимн X (2027)» унаследует его сам.
 */
class HomeworkReviewPolicy
{
    /** Курс принимает ДЗ, но никто его не проверяет. */
    public static function isUnreviewedCourse(?string $courseSlug): bool
    {
        if ($courseSlug === null || $courseSlug === '') {
            return false;
        }

        foreach ((array) config('homework.reviewers.unreviewed_course_prefixes', []) as $prefix) {
            if ((string) $prefix !== '' && str_starts_with($courseSlug, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isUnreviewedSubmission(HomeworkSubmission $submission): bool
    {
        $submission->loadMissing('course');

        return self::isUnreviewedCourse($submission->course?->slug);
    }

    public static function isUnreviewedLesson(Lesson $lesson): bool
    {
        $lesson->loadMissing('course');

        return self::isUnreviewedCourse($lesson->course?->slug);
    }
}
