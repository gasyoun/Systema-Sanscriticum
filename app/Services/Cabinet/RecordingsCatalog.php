<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Owned-recordings catalog (R29.3 / H1572) for the «Записи» page.
 *
 * Shelves (one shelf per course, priority order):
 *  1. completed  — all accessible lessons marked complete
 *  2. lapsed     — LapseDetector hit (unpaid block gap)
 *  3. watching   — mid-progress with active access
 *  4. owned      — active access, idle or not yet started
 *
 * Also surfaces: progress rail eligibility (no homework on course),
 * ownership-expansion offer candidate (R29.5).
 */
final class RecordingsCatalog
{
    public const SHELF_WATCHING = 'watching';

    public const SHELF_OWNED = 'owned';

    public const SHELF_LAPSED = 'lapsed';

    public const SHELF_COMPLETED = 'completed';

    /** Progress fraction above which R29.5 ownership offer may light. */
    public const OWNERSHIP_OFFER_PROGRESS = 0.25;

    public function __construct(
        private readonly LapseDetector $lapses,
    ) {}

    /**
     * @return array{
     *   shelves: array<string, Collection<int, object>>,
     *   ownership_offer: ?object,
     *   lapses: Collection
     * }
     */
    public function forUser(User $user): array
    {
        $lapses = $this->lapses->forUser($user);
        $courses = $this->candidateCourses($user);
        $completedIds = $user->completedLessons()->pluck('lessons.id')->all();

        $shelves = [
            self::SHELF_WATCHING => collect(),
            self::SHELF_OWNED => collect(),
            self::SHELF_LAPSED => collect(),
            self::SHELF_COMPLETED => collect(),
        ];

        $ownershipOffer = null;

        foreach ($courses as $course) {
            $lessons = $course->lessons;
            $total = $lessons->count();
            $done = $lessons->filter(fn (Lesson $l) => in_array($l->id, $completedIds, true))->count();
            $percent = $total > 0 ? $done / $total : 0.0;
            $hasHomework = $lessons->contains(fn (Lesson $l) => $l->hasHomeworkMaterial());
            $isRecordingShape = $course->format === 'recorded' || $course->isCompleted();
            $lapsed = $lapses->get($course->id);

            $card = (object) [
                'course' => $course,
                'total_lessons' => $total,
                'completed_lessons' => $done,
                'percent' => (int) round($percent * 100),
                'has_homework' => $hasHomework,
                'show_rail' => $isRecordingShape && ! $hasHomework && $total > 0,
                'lessons' => $lessons,
                'completed_ids' => $completedIds,
                'is_recording_shape' => $isRecordingShape,
                'lapse' => $lapsed,
                'ribbon' => $lapsed?->label,
                'cta_url' => $lapsed?->renewal_url
                    ?? ($total > 0
                        ? route('student.course', $course->slug)
                        : route('student.dashboard')),
                'cta_label' => $lapsed ? 'Продлить доступ' : 'Открыть',
            ];

            if ($lapsed) {
                $shelves[self::SHELF_LAPSED]->push($card);
            } elseif ($total > 0 && $done >= $total) {
                $shelves[self::SHELF_COMPLETED]->push($card);
            } elseif ($done > 0 && $done < $total) {
                $shelves[self::SHELF_WATCHING]->push($card);
            } else {
                $shelves[self::SHELF_OWNED]->push($card);
            }

            // R29.5: one ownership offer candidate — first recording-shaped
            // course past the progress floor. Caller suppresses in recovery.
            if (
                $ownershipOffer === null
                && $isRecordingShape
                && ! $lapsed
                && $percent >= self::OWNERSHIP_OFFER_PROGRESS
                && $percent < 1.0
                && ! $this->ownsFullForever($user, $course)
            ) {
                $ownershipOffer = (object) [
                    'kind' => 'ownership',
                    'course' => $course,
                    'headline' => 'Продолжение — навсегда на вашей полке',
                    'body' => $course->title.' · вы уже прошли '
                        .$card->percent.'%. Запись останется вашей без расписания.',
                    'cta_url' => route('student.access'),
                    'cta_label' => 'Смотреть варианты доступа',
                    'progress' => $card->percent,
                ];
            }
        }

        return [
            'shelves' => $shelves,
            'ownership_offer' => $ownershipOffer,
            'lapses' => $lapses,
        ];
    }

    /**
     * Courses the student has group membership for (active cabinet access).
     *
     * @return Collection<int, Course>
     */
    private function candidateCourses(User $user): Collection
    {
        $groupIds = $user->groups->pluck('id');
        if ($groupIds->isEmpty()) {
            return collect();
        }

        return Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->with(['lessons' => function ($q) {
                $q->select('id', 'course_id', 'title', 'group_id', 'sort_order', 'created_at', 'homework_enabled', 'homework_prompt')
                    ->orderBy('sort_order')
                    ->orderBy('created_at');
            }])
            ->orderBy('title')
            ->get();
    }

    /**
     * Heuristic: paid a non-block tariff (full/recording-ish) → no expansion offer.
     */
    private function ownsFullForever(User $user, Course $course): bool
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('is_conditional', false)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where(function ($q) {
                $q->whereNull('start_block')
                    ->orWhere('tariff', 'like', '%full%')
                    ->orWhere('tariff', 'like', '%record%');
            })
            ->exists();
    }
}
