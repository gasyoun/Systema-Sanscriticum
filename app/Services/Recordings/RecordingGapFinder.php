<?php

declare(strict_types=1);

namespace App\Services\Recordings;

use App\Models\Lesson;
use App\Models\Schedule;
use Carbon\CarbonImmutable;

/**
 * Join is storeFromZoom's: schedules.start date + course_id → lessons.lesson_date + course_id.
 * lessons.group_id is NULL on those Zoom rows; schedules has no lesson_id.
 */
final class RecordingGapFinder
{
    /**
     * @return list<array{schedule_id: int, lesson_date: string, start: string, course_id: int, course: string, group_id: ?int, group: string, chat_id: string, reason: string}>
     */
    public function gaps(CarbonImmutable $from, CarbonImmutable $until, bool $includeWithoutChat): array
    {
        $skipTitles = array_map('mb_strtolower', config('recording_gap.skip_title_substrings', []));
        $skipCourseIds = array_map('intval', config('recording_gap.skip_course_ids', []));

        $schedules = Schedule::query()
            ->with(['course', 'group'])
            ->whereNotNull('course_id')
            ->where('start', '>=', $from)
            ->where('start', '<=', $until)
            ->orderBy('start')
            ->get();

        $gaps = [];

        foreach ($schedules as $schedule) {
            $title = (string) ($schedule->title ?? '');
            $titleFold = mb_strtolower($title);
            $skippedByTitle = false;
            foreach ($skipTitles as $needle) {
                if ($needle !== '' && str_contains($titleFold, $needle)) {
                    $skippedByTitle = true;
                    break;
                }
            }
            if ($skippedByTitle) {
                continue;
            }

            $courseId = (int) $schedule->course_id;
            if ($courseId > 0 && in_array($courseId, $skipCourseIds, true)) {
                continue;
            }

            $chatId = trim((string) ($schedule->group?->telegram_chat_id ?? ''));
            if (! $includeWithoutChat && $chatId === '') {
                continue;
            }

            $lessonDate = $schedule->start->timezone((string) config('app.timezone'))->toDateString();
            if ($this->hasPostedRecording($courseId, $lessonDate)) {
                continue;
            }

            $hasLesson = Lesson::query()
                ->where('course_id', $courseId)
                ->whereDate('lesson_date', $lessonDate)
                ->exists();

            $gaps[] = [
                'schedule_id' => (int) $schedule->id,
                'lesson_date' => $lessonDate,
                'start' => $schedule->start->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                'course_id' => $courseId,
                'course' => (string) ($schedule->course?->title ?? ''),
                'group_id' => $schedule->group_id !== null ? (int) $schedule->group_id : null,
                'group' => (string) ($schedule->group?->name ?? ''),
                'chat_id' => $chatId,
                'reason' => $hasLesson ? 'no-recording' : 'no-lesson',
            ];
        }

        return $gaps;
    }

    private function hasPostedRecording(int $courseId, string $lessonDate): bool
    {
        return Lesson::query()
            ->where('course_id', $courseId)
            ->whereDate('lesson_date', $lessonDate)
            ->where('is_published', true)
            ->get()
            ->contains(function (Lesson $lesson): bool {
                return $lesson->hasVideo() || filled($lesson->recording_attached_at);
            });
    }
}
