<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * H2333 — self-service “where is lesson 1?” for continuation course shells.
 *
 * When a live stream only lists lessons from N onwards (e.g. Hindi gr.5 from #13),
 * the admin points predecessor_course_id at the shell that holds 1…N−1.
 * This service builds the student-facing banner payload (or null if nothing to show).
 */
final class CourseContinuationBanner
{
    /**
     * @return array{
     *     continues_from: int,
     *     predecessor: Course,
     *     predecessor_title: string,
     *     student_enrolled: bool,
     *     course_url: string,
     *     first_lesson_url: string|null,
     * }|null
     */
    public function for(Course $course, ?User $user = null): ?array
    {
        if (! Schema::hasColumn('courses', 'predecessor_course_id')) {
            return null;
        }

        $predecessorId = $course->predecessor_course_id;
        if ($predecessorId === null) {
            return null;
        }

        $predecessor = $course->relationLoaded('predecessor')
            ? $course->predecessor
            : Course::query()->find($predecessorId);

        if ($predecessor === null || ! $predecessor->is_active) {
            return null;
        }

        $continuesFrom = $course->continues_from_lesson !== null
            ? (int) $course->continues_from_lesson
            : $this->inferContinuesFrom($course);

        $studentEnrolled = false;
        if ($user !== null) {
            $studentEnrolled = $user->groups()
                ->whereIn(
                    'groups.id',
                    $predecessor->groups()->pluck('groups.id')
                )
                ->exists();
        }

        $firstLesson = Lesson::query()
            ->where('course_id', $predecessor->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $courseUrl = $studentEnrolled
            ? route('student.course', $predecessor->slug)
            : route('shop.course.show', $predecessor->slug);

        $firstLessonUrl = null;
        if ($studentEnrolled && $firstLesson !== null) {
            $firstLessonUrl = route('student.lesson', [$predecessor->slug, $firstLesson->id]);
        }

        return [
            'continues_from' => $continuesFrom,
            'predecessor' => $predecessor,
            'predecessor_title' => $predecessor->title,
            'student_enrolled' => $studentEnrolled,
            'course_url' => $courseUrl,
            'first_lesson_url' => $firstLessonUrl,
        ];
    }

    /**
     * Best-effort parse of the first listed lesson title (“13-е занятие”, “#13”, …).
     * Falls back to 2 when the shell is marked as a continuation without a number.
     */
    public function inferContinuesFrom(Course $course): int
    {
        $title = (string) Lesson::query()
            ->where('course_id', $course->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('title');

        // Prefer “13-е занятие” over “начальная №5” (group number ≠ lesson number).
        if ($title !== '' && preg_match('/(\d{1,3})\s*[-.]?\s*(?:е|ое|й)?\s*занят/ui', $title, $m)) {
            return max(1, (int) $m[1]);
        }

        if ($title !== '' && preg_match('/#\s*(\d{1,3})\b/u', $title, $m)) {
            return max(1, (int) $m[1]);
        }

        return 2;
    }
}
