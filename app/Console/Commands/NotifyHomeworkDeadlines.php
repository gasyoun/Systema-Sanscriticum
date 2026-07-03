<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\HomeworkDeadlineService;
use App\Services\HomeworkNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyHomeworkDeadlines extends Command
{
    protected $signature = 'homework:notify-deadlines';

    protected $description = 'Шлёт дедлайн-уведомления по ДЗ, отмечает late/locked и дедуплицирует пинги.';

    public function handle(HomeworkDeadlineService $deadlines, HomeworkNotificationService $notifications): int
    {
        $lessons = Lesson::query()
            ->with(['course.groups.activeUsers', 'group.activeUsers', 'homeworkSubmissions.user', 'deadlineOverrides'])
            ->where('homework_enabled', true)
            ->whereRaw("COALESCE(homework_prompt, '') <> ''")
            ->get();

        $opened = 0;
        $deadlineNear = 0;
        $locked = 0;
        $late = 0;

        foreach ($lessons as $lesson) {
            foreach ($this->audience($lesson) as $student) {
                $submission = $lesson->homeworkSubmissions->firstWhere('user_id', $student->id);
                if ($submission?->status === HomeworkSubmission::STATUS_ACCEPTED) {
                    continue;
                }

                $window = $deadlines->windowFor($student, $lesson);

                if (($window['opens_at'] === null || $window['opens_at']->isPast())
                    && ($window['locks_at'] === null || $window['locks_at']->isFuture())) {
                    $before = \App\Models\HomeworkNotificationLog::count();
                    $notifications->queueForStudentLesson($student, $lesson, HomeworkNotificationService::EVENT_OPENED);
                    $opened += \App\Models\HomeworkNotificationLog::count() > $before ? 1 : 0;
                }

                if ($window['due_at'] && $window['due_at']->isBetween(now(), now()->addDay(), true)) {
                    $before = \App\Models\HomeworkNotificationLog::count();
                    $notifications->queueForStudentLesson($student, $lesson, HomeworkNotificationService::EVENT_DEADLINE_NEAR);
                    $deadlineNear += \App\Models\HomeworkNotificationLog::count() > $before ? 1 : 0;
                }

                if ($window['locks_at'] && $window['locks_at']->isPast()) {
                    $submission ??= HomeworkSubmission::create([
                        'user_id' => $student->id,
                        'lesson_id' => $lesson->id,
                        'course_id' => $lesson->course_id,
                        'status' => HomeworkSubmission::STATUS_LOCKED,
                        'last_activity_at' => now(),
                        'locked_at' => now(),
                    ]);

                    if ($submission->status !== HomeworkSubmission::STATUS_LOCKED) {
                        $submission->update([
                            'status' => HomeworkSubmission::STATUS_LOCKED,
                            'locked_at' => now(),
                            'last_activity_at' => now(),
                        ]);
                        $locked++;
                    }

                    $notifications->queue($submission->fresh(), HomeworkNotificationService::EVENT_LOCKED, dedupe: true);

                    continue;
                }

                if ($window['due_at'] && $window['due_at']->isPast() && $submission && $submission->isEditableByStudent()) {
                    $submission->update([
                        'status' => HomeworkSubmission::STATUS_LATE,
                        'last_activity_at' => now(),
                    ]);
                    $late++;
                }
            }
        }

        $this->info("Homework notifications: opened {$opened}, deadline {$deadlineNear}, late {$late}, locked {$locked}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function audience(Lesson $lesson): Collection
    {
        $students = collect();

        if ($lesson->group) {
            $students = $students->merge($lesson->group->activeUsers);
        } elseif ($lesson->course) {
            foreach ($lesson->course->groups as $group) {
                $students = $students->merge($group->activeUsers);
            }
        }

        $students = $students->merge($lesson->homeworkSubmissions->pluck('user')->filter());

        return $students->unique('id')->values();
    }
}
