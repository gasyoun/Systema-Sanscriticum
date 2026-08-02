<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use Illuminate\Support\Collection;

/**
 * Детектор «забытых» курсов: активная группа, состоялось >= threshold занятий
 * (course_missing_milestones_threshold, MarketingSetting), а активных вех
 * сертификатов нет — курс помечается (courses.milestones_nudge_sent_at, он же
 * badge «требует внимания» и дедуп) и кураторский чат получает уведомление.
 * Штамп сбрасывается created-хуком CertificateMilestone — детектор перевзведётся,
 * если вехи потом удалят.
 */
class MissingMilestoneCourseDetector
{
    public function __construct(private readonly CuratorNotifier $curatorNotifier) {}

    /** Возвращает уведомлённые курсы. */
    public function run(): Collection
    {
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->course_missing_milestones_notify_enabled) {
            return collect();
        }

        $threshold = max(1, (int) ($settings?->course_missing_milestones_threshold ?? 12));

        $candidates = Course::query()
            ->whereNull('milestones_nudge_sent_at')
            ->whereDoesntHave('certificateMilestones', fn ($q) => $q->where('is_active', true))
            ->whereHas('groups', fn ($q) => $q->where('status', 'active'))
            ->with(['groups' => fn ($q) => $q->where('status', 'active')])
            ->get();

        return $candidates
            ->map(fn (Course $course) => [
                'course' => $course,
                'lessons' => $this->maxPastLessons($course),
            ])
            ->filter(fn (array $r) => $r['lessons'] >= $threshold)
            ->map(function (array $r): Course {
                $this->curatorNotifier->courseMissingMilestones($r['course'], $r['lessons']);
                $r['course']->forceFill(['milestones_nudge_sent_at' => now()])->save();

                return $r['course'];
            })
            ->values();
    }

    /** Максимум состоявшихся занятий по активным группам курса. */
    public function maxPastLessons(Course $course): int
    {
        return (int) $course->groups
            ->map(fn (Group $group) => Lesson::query()
                ->where('course_id', $course->id)
                ->where(fn ($q) => $q->whereNull('group_id')->orWhere('group_id', $group->id))
                ->whereNotNull('lesson_date')
                ->whereDate('lesson_date', '<=', today())
                ->count())
            ->max() ?? 0;
    }
}
