<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * H2442 — multi-hop predecessor walk for programme course shells.
 *
 * H2333 is 1-hop (cabinet banner). This service walks A→B→C without cycles
 * so H2441 (playlist) and future languages can consume one ordered list.
 * Read-only: does not write payments, groups, or access keys.
 */
final class ProgrammeShellGraph
{
    public const DEFAULT_PROGRAMME = 'hindi';

    public const MAX_HOPS = 50;

    /**
     * Ordered shells for a programme key (`config/programme.{key}`).
     *
     * @return Collection<int, Course>
     */
    public function orderedShells(string $programmeKey = self::DEFAULT_PROGRAMME): Collection
    {
        return $this->expandAndOrder($this->seedShells($programmeKey));
    }

    /**
     * Walk predecessors from a course (inclusive), earliest-first.
     * Missing FK is skipped. Cycles stop.
     *
     * @return Collection<int, Course>
     */
    public function walkFrom(Course $course): Collection
    {
        return $this->expandAndOrder(collect([$course]));
    }

    /**
     * True if setting $courseId.predecessor = $predecessorId would cycle.
     */
    public function wouldCycle(?int $courseId, ?int $predecessorId): bool
    {
        if ($courseId === null || $predecessorId === null) {
            return false;
        }
        if ($courseId === $predecessorId) {
            return true;
        }

        $seen = [$courseId => true];
        $id = $predecessorId;
        $guard = 0;
        while ($id !== null && $guard < self::MAX_HOPS) {
            $guard++;
            if (isset($seen[$id])) {
                return true;
            }
            $seen[$id] = true;
            $id = $this->predecessorIdOf($id);
        }

        return false;
    }

    /**
     * Expand a seed set along predecessor_course_id, then order earliest-first.
     *
     * @param  Collection<int, Course>  $seeds
     * @return Collection<int, Course>
     */
    public function expandAndOrder(Collection $seeds): Collection
    {
        if ($seeds->isEmpty()) {
            return collect();
        }

        $byId = $seeds->keyBy(fn (Course $c) => (int) $c->id);

        if (! Schema::hasColumn('courses', 'predecessor_course_id')) {
            return $this->orderByPredecessorChain($byId->values());
        }

        $queue = $seeds
            ->pluck('predecessor_course_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $guard = 0;
        while ($queue !== [] && $guard < self::MAX_HOPS) {
            $guard++;
            $id = (int) array_pop($queue);
            if ($byId->has($id)) {
                continue;
            }
            $course = Course::query()->whereKey($id)->where('is_active', true)->with('groups')->first();
            if ($course === null) {
                continue;
            }
            $byId->put($id, $course);
            if ($course->predecessor_course_id) {
                $queue[] = (int) $course->predecessor_course_id;
            }
        }

        return $this->orderByPredecessorChain($byId->values());
    }

    /**
     * Category slug + configured IDs + optional title/slug likes.
     * Unknown programme key (no config entry) returns empty.
     *
     * @return Collection<int, Course>
     */
    public function seedShells(string $programmeKey): Collection
    {
        $cfg = config('programme.'.$programmeKey);
        if (! is_array($cfg)) {
            return collect();
        }

        $slug = (string) ($cfg['category_slug'] ?? $programmeKey);
        $ids = array_values(array_filter(array_map('intval', (array) ($cfg['course_ids'] ?? []))));
        $titleLikes = array_values(array_filter(array_map('strval', (array) ($cfg['title_likes'] ?? []))));
        $slugLikes = array_values(array_filter(array_map('strval', (array) ($cfg['slug_likes'] ?? []))));

        if ($programmeKey === 'hindi' && $titleLikes === [] && $slugLikes === []) {
            $titleLikes = ['%хинди%'];
            $slugLikes = ['%hindi%', '%xindi%'];
        }

        return Course::query()
            ->where('is_active', true)
            ->where(function ($q) use ($slug, $ids, $titleLikes, $slugLikes): void {
                if ($slug !== '') {
                    $q->orWhereHas('categories', fn ($c) => $c->where('slug', $slug));
                }
                if ($ids !== []) {
                    $q->orWhereIn('id', $ids);
                }
                foreach ($titleLikes as $like) {
                    $q->orWhere('title', 'like', $like);
                }
                foreach ($slugLikes as $like) {
                    $q->orWhere('slug', 'like', $like);
                }
            })
            ->with('groups')
            ->get();
    }

    private function predecessorIdOf(int $courseId): ?int
    {
        if (! Schema::hasColumn('courses', 'predecessor_course_id')) {
            return null;
        }

        $raw = Course::query()->whereKey($courseId)->value('predecessor_course_id');

        return $raw !== null ? (int) $raw : null;
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @return Collection<int, Course>
     */
    private function orderByPredecessorChain(Collection $courses): Collection
    {
        if ($courses->isEmpty()) {
            return $courses;
        }

        $byId = $courses->keyBy(fn (Course $c) => (int) $c->id);
        $visited = [];
        $ordered = [];

        $walk = function (Course $course) use (&$walk, &$visited, &$ordered, $byId): void {
            $id = (int) $course->id;
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $ordered[] = $course;
            $byId
                ->filter(fn (Course $c) => (int) $c->predecessor_course_id === $id)
                ->sortBy(fn (Course $c) => [(int) ($c->continues_from_lesson ?? 1), (int) $c->id])
                ->each(fn (Course $c) => $walk($c));
        };

        $courses
            ->filter(function (Course $c) use ($byId): bool {
                $pred = $c->predecessor_course_id;

                return $pred === null || ! $byId->has((int) $pred);
            })
            ->sortBy(fn (Course $c) => [(int) ($c->continues_from_lesson ?? 1), (int) $c->id])
            ->each(fn (Course $c) => $walk($c));

        $courses
            ->sortBy(fn (Course $c) => (int) $c->id)
            ->each(function (Course $c) use (&$visited, &$ordered): void {
                if (! isset($visited[(int) $c->id])) {
                    $visited[(int) $c->id] = true;
                    $ordered[] = $c;
                }
            });

        return collect($ordered)->values();
    }
}
