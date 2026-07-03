<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HomeworkDeadlineOverride;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;

class HomeworkDeadlineService
{
    /** @return array{opens_at: ?CarbonInterface, due_at: ?CarbonInterface, locks_at: ?CarbonInterface, override: ?HomeworkDeadlineOverride} */
    public function windowFor(User $student, Lesson $lesson): array
    {
        $lesson->loadMissing('deadlineOverrides');

        $override = $lesson->deadlineOverrides
            ->first(fn (HomeworkDeadlineOverride $o): bool => (int) $o->user_id === (int) $student->id);

        if (! $override) {
            $groupIds = $student->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
            $override = $lesson->deadlineOverrides
                ->first(fn (HomeworkDeadlineOverride $o): bool => $o->group_id !== null && in_array((int) $o->group_id, $groupIds, true));
        }

        $opensAt = $override?->opens_at ?? $lesson->homework_opens_at;
        $dueAt = $override?->due_at ?? $lesson->homework_due_at;
        $locksAt = $override?->locks_at ?? $lesson->homework_locks_at;

        if (! $locksAt && $dueAt) {
            $locksAt = $dueAt->copy()->addDay();
        }

        return [
            'opens_at' => $opensAt,
            'due_at' => $dueAt,
            'locks_at' => $locksAt,
            'override' => $override,
        ];
    }

    public function isOpen(User $student, Lesson $lesson): bool
    {
        $opensAt = $this->windowFor($student, $lesson)['opens_at'];

        return ! $opensAt || $opensAt->isPast();
    }

    public function isLocked(User $student, Lesson $lesson): bool
    {
        $locksAt = $this->windowFor($student, $lesson)['locks_at'];

        return $locksAt !== null && $locksAt->isPast();
    }

    public function isLate(User $student, Lesson $lesson): bool
    {
        $window = $this->windowFor($student, $lesson);

        return $window['due_at'] !== null
            && $window['due_at']->isPast()
            && ($window['locks_at'] === null || $window['locks_at']->isFuture());
    }

    public function rejectReasonForSubmit(User $student, Lesson $lesson, ?HomeworkSubmission $submission = null): ?string
    {
        if (! $this->isOpen($student, $lesson)) {
            return 'Домашнее задание ещё не открыто для сдачи.';
        }

        if ($this->isLocked($student, $lesson) || $submission?->status === HomeworkSubmission::STATUS_LOCKED) {
            return 'Срок сдачи истёк. Обратитесь к преподавателю, чтобы открыть работу заново.';
        }

        return null;
    }
}
