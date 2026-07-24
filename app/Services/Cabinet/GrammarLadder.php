<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * MAIN grammar ladder for hybrid «Прогресс» (R29.6–R29.7 / H1573).
 *
 * Stations from config/grammar_ladder.php; courses matched by title LIKE.
 * Status per station for a student:
 *  - locked     — no matched course, or previous station not completed
 *  - current    — first incomplete station the student can work on
 *  - completed  — all accessible lessons done (or zero lessons + certificate)
 *  - available  — matched course exists but student not yet on path
 *
 * Ladder offer (R29.7): lights only when the *current* station is fully
 * completed and a next station exists — wording «станция подождёт», no timers.
 * Caller suppresses the offer under recovery (offer precedence).
 */
final class GrammarLadder
{
    public const STATUS_LOCKED = 'locked';

    public const STATUS_CURRENT = 'current';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_AVAILABLE = 'available';

    /**
     * @return array{
     *   stations: list<object>,
     *   ladder_offer: ?object,
     *   lit_keys: list<string>
     * }
     */
    public function forUser(User $user): array
    {
        $defs = config('grammar_ladder.stations', []);
        $groupIds = $user->groups->pluck('id');
        $completedIds = $user->completedLessons()->pluck('lessons.id')->all();
        $certCourseIds = $user->certificates()->pluck('course_id')->all();

        $raw = [];
        foreach ($defs as $def) {
            $course = $this->matchCourse($def['pattern'] ?? '');
            $hasAccess = $course && $groupIds->isNotEmpty()
                && $course->groups()->whereIn('groups.id', $groupIds)->exists();

            $total = 0;
            $done = 0;
            if ($course && $hasAccess) {
                $lessons = $course->lessons;
                $total = $lessons->count();
                $done = $lessons->filter(fn ($l) => in_array($l->id, $completedIds, true))->count();
            }

            $completed = false;
            if ($course && $hasAccess) {
                $completed = ($total > 0 && $done >= $total)
                    || ($total === 0 && in_array($course->id, $certCourseIds, true))
                    || in_array($course->id, $certCourseIds, true);
            }

            $raw[] = (object) [
                'key' => $def['key'],
                'title' => $def['title'],
                'description' => $def['description'] ?? '',
                'pattern' => $def['pattern'] ?? '',
                'course' => $course,
                'has_access' => (bool) $hasAccess,
                'total_lessons' => $total,
                'completed_lessons' => $done,
                'percent' => $total > 0 ? (int) round(($done / $total) * 100) : ($completed ? 100 : 0),
                'is_completed' => $completed,
                'status' => self::STATUS_LOCKED, // filled below
            ];
        }

        // Walk left→right: first incomplete with access is current; prior complete stay lit.
        $foundCurrent = false;
        $prevComplete = true;
        foreach ($raw as $station) {
            if (! $station->course) {
                $station->status = self::STATUS_LOCKED;
                $prevComplete = false;

                continue;
            }

            if ($station->is_completed && $prevComplete) {
                $station->status = self::STATUS_COMPLETED;
                $prevComplete = true;

                continue;
            }

            if (! $foundCurrent && $prevComplete && $station->has_access) {
                $station->status = self::STATUS_CURRENT;
                $foundCurrent = true;
                $prevComplete = false;

                continue;
            }

            if ($station->has_access && $prevComplete) {
                $station->status = self::STATUS_AVAILABLE;
            } else {
                $station->status = self::STATUS_LOCKED;
            }
            $prevComplete = false;
        }

        $litKeys = collect($raw)
            ->filter(fn ($s) => $s->status === self::STATUS_COMPLETED)
            ->pluck('key')
            ->values()
            ->all();

        $ladderOffer = $this->buildLadderOffer($raw);

        return [
            'stations' => $raw,
            'ladder_offer' => $ladderOffer,
            'lit_keys' => $litKeys,
        ];
    }

    /**
     * Course-home landmarks (R29.8): block start/end dates as orientation vehi.
     *
     * @return list<object{label: string, at: ?Carbon, kind: string}>
     */
    public function landmarksForCourse(Course $course): array
    {
        $blocks = $course->blocks()
            ->orderBy('number')
            ->get(['id', 'number', 'title', 'starts_at', 'ends_at', 'is_current']);

        $landmarks = [];
        foreach ($blocks as $block) {
            $title = $block->title ?: ('Блок '.$block->number);
            if ($block->starts_at) {
                $landmarks[] = (object) [
                    'label' => $title.' — начало',
                    'at' => $block->starts_at,
                    'kind' => 'block_start',
                    'block_number' => (int) $block->number,
                ];
            }
            if ($block->ends_at) {
                $landmarks[] = (object) [
                    'label' => $title.' — контур',
                    'at' => $block->ends_at,
                    'kind' => 'block_end',
                    'block_number' => (int) $block->number,
                ];
            }
        }

        usort($landmarks, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        return $landmarks;
    }

    private function matchCourse(string $pattern): ?Course
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $pattern).'%';

        return Course::query()
            ->where('is_active', true)
            ->where('title', 'LIKE', $like)
            ->with(['lessons' => fn ($q) => $q->select('id', 'course_id', 'title', 'sort_order')->orderBy('sort_order')])
            ->with('groups:id')
            ->orderBy('id')
            ->first();
    }

    /**
     * R29.7: offer only after current station fully completed; next station waits.
     *
     * @param  list<object>  $stations
     */
    private function buildLadderOffer(array $stations): ?object
    {
        $completedIdx = null;
        foreach ($stations as $i => $s) {
            if ($s->status === self::STATUS_COMPLETED) {
                $completedIdx = $i;
            }
        }

        if ($completedIdx === null) {
            return null;
        }

        // Offer lights for the *next* station after the furthest completed run.
        $next = $stations[$completedIdx + 1] ?? null;
        if (! $next || ! $next->course) {
            return null;
        }

        // Only if the completed run is contiguous from the start.
        for ($i = 0; $i <= $completedIdx; $i++) {
            if ($stations[$i]->status !== self::STATUS_COMPLETED) {
                return null;
            }
        }

        return (object) [
            'kind' => 'ladder',
            'from_key' => $stations[$completedIdx]->key,
            'to_key' => $next->key,
            'headline' => 'Станция «'.$stations[$completedIdx]->title.'» пройдена',
            'body' => 'Дальше — «'.$next->title.'». Станция подождёт: без таймеров и дедлайнов.',
            'cta_label' => $next->has_access ? 'Открыть станцию' : 'Смотреть курс',
            'cta_url' => $next->has_access
                ? route('student.course', $next->course->slug)
                : route('shop.course.show', $next->course->slug),
            'course' => $next->course,
        ];
    }
}
