<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendHomeworkNotificationJob;
use App\Models\HomeworkNotificationLog;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\User;

class HomeworkNotificationService
{
    public const EVENT_OPENED = 'opened';

    public const EVENT_DEADLINE_NEAR = 'deadline_near';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_RETURNED = 'returned';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_LOCKED = 'locked';

    public function queue(HomeworkSubmission $submission, string $event, bool $dedupe = false): void
    {
        if (! $this->enabled($event)) {
            return;
        }

        if ($dedupe && ! $this->markSent($submission->lesson_id, $submission->user_id, $submission->id, $event)) {
            return;
        }

        SendHomeworkNotificationJob::dispatch($submission->id, $event);
    }

    public function queueForStudentLesson(User $student, Lesson $lesson, string $event, bool $dedupe = true): void
    {
        if (! $this->enabled($event)) {
            return;
        }

        if ($dedupe && ! $this->markSent($lesson->id, $student->id, null, $event)) {
            return;
        }

        SendHomeworkNotificationJob::dispatch(null, $event, $student->id, $lesson->id);
    }

    private function enabled(string $event): bool
    {
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->homework_notifications_enabled) {
            return false;
        }

        return match ($event) {
            self::EVENT_OPENED => (bool) ($settings?->homework_notify_opened ?? true),
            self::EVENT_DEADLINE_NEAR => (bool) ($settings?->homework_notify_deadline_near ?? true),
            self::EVENT_SUBMITTED => (bool) ($settings?->homework_notify_submitted ?? true),
            self::EVENT_RETURNED => (bool) ($settings?->homework_notify_returned ?? true),
            self::EVENT_ACCEPTED => (bool) ($settings?->homework_notify_accepted ?? true),
            self::EVENT_LOCKED => (bool) ($settings?->homework_notify_locked ?? true),
            default => true,
        };
    }

    private function markSent(int $lessonId, ?int $userId, ?int $submissionId, string $event): bool
    {
        $log = HomeworkNotificationLog::firstOrCreate([
            'lesson_id' => $lessonId,
            'user_id' => $userId,
            'submission_id' => $submissionId,
            'event' => $event,
            'channel' => 'workflow',
        ], [
            'sent_at' => now(),
        ]);

        return $log->wasRecentlyCreated;
    }
}
