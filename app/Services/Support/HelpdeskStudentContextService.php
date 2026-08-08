<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\ClassAttendanceService;
use Illuminate\Support\Collection;

/**
 * EdTech P0 context for the Helpdesk student-info modal (H2381).
 *
 * Access answers reuse Payment paid tariffs + Lesson::isUnlockedBy — never a
 * parallel key table. Progressive disclosure fields for courses/groups,
 * block-level access, nearest schedule/Zoom, next course lesson, recent threads.
 */
class HelpdeskStudentContextService
{
    public function __construct(
        private readonly ClassAttendanceService $attendance,
    ) {}

    /**
     * Full payload for Helpdesk::getStudentInfoProperty.
     *
     * @return array{
     *     user: User,
     *     payments: Collection,
     *     payments_count: int,
     *     payments_total: float,
     *     promises: Collection,
     *     discounts: Collection,
     *     attendance: array,
     *     groups: array<int, array{id:int, name:string, courses:array<int, string>}>,
     *     access: array<int, array{course_id:int, course_title:string, tariffs:array<int, string>, blocks:array<int, array{block:int, unlocked:bool}>}>,
     *     next_schedule: ?array{id:int, title:string, start:?string, link:?string, group:?string, course:?string},
     *     next_lesson: ?array{id:int, title:string, course:?string, block:?int},
     *     recent_conversations: array<int, array{id:int, status:string, subject:?string, last_message_at:?string, closed_at:?string}>
     * }
     */
    public function forUser(User $user): array
    {
        $payments = $user->payments()->real()->with('course')->latest()->limit(8)->get();

        return [
            'user' => $user,
            'payments' => $payments,
            'payments_count' => $user->payments()->real()->count(),
            'payments_total' => (float) $user->payments()->real()->sum('amount'),
            'promises' => $user->paymentPromises()
                ->with('course')
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('promised_at')
                ->get(),
            'discounts' => $user->individualDiscounts()
                ->where('is_active', true)
                ->with('course')
                ->get(),
            'attendance' => $this->attendance->forStudent($user, 8),
            'groups' => $this->activeGroups($user),
            'access' => $this->blockAccess($user),
            'next_schedule' => $this->nextSchedule($user),
            'next_lesson' => $this->nextLesson($user),
            'recent_conversations' => $this->recentConversations($user),
        ];
    }

    /**
     * @return array<int, array{id:int, name:string, courses:array<int, string>}>
     */
    private function activeGroups(User $user): array
    {
        return $user->activeGroups()
            ->with('courses:id,title')
            ->orderBy('name')
            ->get()
            ->map(fn ($group) => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'courses' => $group->courses->pluck('title')->filter()->values()->all(),
            ])
            ->all();
    }

    /**
     * Per-course owned tariff keys + whether each block_number is unlocked via
     * Lesson::isUnlockedBy (canonical money/access path).
     *
     * @return array<int, array{course_id:int, course_title:string, tariffs:array<int, string>, blocks:array<int, array{block:int, unlocked:bool}>}>
     */
    private function blockAccess(User $user): array
    {
        $courseIds = Payment::query()
            ->where('user_id', $user->id)
            ->paid()
            ->whereNotNull('course_id')
            ->distinct()
            ->pluck('course_id')
            ->merge(
                $user->activeGroups()
                    ->with('courses:id')
                    ->get()
                    ->flatMap(fn ($g) => $g->courses->pluck('id'))
            )
            ->unique()
            ->filter()
            ->values();

        if ($courseIds->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($courseIds as $courseId) {
            $tariffs = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->paid()
                ->pluck('tariff')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $lessons = Lesson::query()
                ->where('course_id', $courseId)
                ->whereNotNull('block_number')
                ->orderBy('block_number')
                ->get(['id', 'course_id', 'title', 'block_number', 'block_half', 'is_preview', 'is_free']);

            if ($lessons->isEmpty() && $tariffs === []) {
                continue;
            }

            $courseTitle = $lessons->first()?->course?->title
                ?? Payment::query()->where('course_id', $courseId)->with('course')->first()?->course?->title
                ?? ('Курс #'.$courseId);

            // Reload title via course relation if needed
            if ($courseTitle === 'Курс #'.$courseId) {
                $course = Course::find($courseId);
                $courseTitle = $course?->title ?? $courseTitle;
            }

            $blocks = [];
            foreach ($lessons->groupBy('block_number') as $blockNum => $blockLessons) {
                $unlocked = $blockLessons->contains(
                    fn (Lesson $lesson) => $lesson->is_free
                        || $lesson->isUnlockedBy($tariffs)
                );
                $blocks[] = [
                    'block' => (int) $blockNum,
                    'unlocked' => $unlocked,
                ];
            }

            $out[] = [
                'course_id' => (int) $courseId,
                'course_title' => (string) $courseTitle,
                'tariffs' => array_values($tariffs),
                'blocks' => $blocks,
            ];
        }

        return $out;
    }

    /**
     * @return ?array{id:int, title:string, start:?string, link:?string, group:?string, course:?string}
     */
    private function nextSchedule(User $user): ?array
    {
        $groupIds = $user->activeGroups()->pluck('groups.id');

        if ($groupIds->isEmpty()) {
            return null;
        }

        $schedule = Schedule::query()
            ->with(['course:id,title', 'group:id,name'])
            ->whereIn('group_id', $groupIds)
            ->where(function ($q) {
                $q->where('end', '>=', now())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('end')->where('start', '>=', now()->subHours(3));
                    });
            })
            ->orderBy('start')
            ->first();

        if (! $schedule) {
            return null;
        }

        return [
            'id' => (int) $schedule->id,
            'title' => (string) $schedule->title,
            'start' => $schedule->start?->format('d.m.Y H:i'),
            'link' => $schedule->link ?: $schedule->zoom_join_url,
            'group' => $schedule->group?->name,
            'course' => $schedule->course?->title,
        ];
    }

    /**
     * First not-yet-completed lesson the student can see on an active course.
     *
     * @return ?array{id:int, title:string, course:?string, block:?int}
     */
    private function nextLesson(User $user): ?array
    {
        $groupIds = $user->activeGroups()->pluck('groups.id');
        $completedIds = $user->completedLessons()->pluck('lessons.id')->all();

        $courses = Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->with(['lessons' => fn ($q) => $q->orderBy('sort_order')->orderBy('created_at')])
            ->get();

        foreach ($courses as $course) {
            $tariffs = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->paid()
                ->pluck('tariff')
                ->all();

            foreach ($course->lessons as $lesson) {
                if (in_array($lesson->id, $completedIds, true)) {
                    continue;
                }

                $open = $lesson->is_free
                    || $lesson->is_preview
                    || $lesson->isUnlockedBy($tariffs);

                if (! $open) {
                    // Still show the first locked lesson as "next" so access faults surface.
                    return [
                        'id' => (int) $lesson->id,
                        'title' => (string) $lesson->title.' (закрыт)',
                        'course' => $course->title,
                        'block' => $lesson->block_number !== null ? (int) $lesson->block_number : null,
                    ];
                }

                return [
                    'id' => (int) $lesson->id,
                    'title' => (string) $lesson->title,
                    'course' => $course->title,
                    'block' => $lesson->block_number !== null ? (int) $lesson->block_number : null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id:int, status:string, subject:?string, last_message_at:?string, closed_at:?string}>
     */
    private function recentConversations(User $user): array
    {
        return SupportConversation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (SupportConversation $c) => [
                'id' => (int) $c->id,
                'status' => (string) $c->status,
                'subject' => $c->subject,
                'last_message_at' => $c->last_message_at?->format('d.m.Y H:i'),
                'closed_at' => $c->closed_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }
}
