<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;
use Illuminate\Support\Collection;

/**
 * H2441 — one ordered Hindi lesson list across owned course shells.
 *
 * Shell order comes from ProgrammeShellGraph (H2442). Access is reused,
 * not re-derived: group gate + AccessDiagnosticsService + ClubEntitlement
 * virtual keys. Does not write payments or merge courses.
 */
final class HindiProgrammePlaylist
{
    public const CATEGORY_SLUG = 'hindi';

    public function __construct(
        private readonly AccessDiagnosticsService $diagnostics,
        private readonly ClubEntitlement $club,
        private readonly ProgrammeShellGraph $graph,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('features.hindi_programme_playlist', false);
    }

    /**
     * @return array{eligible: bool, count: int}
     */
    public function summaryFor(User $user): array
    {
        $items = $this->itemsFor($user);

        return [
            'eligible' => $items->isNotEmpty(),
            'count' => $items->count(),
        ];
    }

    /**
     * Accessible published lessons, predecessor-chain then sort_order.
     *
     * @return Collection<int, array{
     *     lesson: Lesson,
     *     course: Course,
     *     shell_label: string,
     *     url: string
     * }>
     */
    public function itemsFor(User $user): Collection
    {
        $user->loadMissing('groups');
        $items = collect();

        foreach ($this->orderedShells() as $shell) {
            foreach ($this->accessibleLessons($user, $shell) as $lesson) {
                $items->push([
                    'lesson' => $lesson,
                    'course' => $shell,
                    'shell_label' => $this->shellLabel($shell),
                    'url' => route('student.lesson', [$shell->slug, $lesson->id]),
                ]);
            }
        }

        return $items->values();
    }

    /**
     * Read-only per-shell counts for the prod probe (user 6494).
     *
     * @return array{
     *     user_id: int,
     *     flag: bool,
     *     shells: list<array{id: int, slug: string, label: string, published: int, accessible: int}>,
     *     accessible_total: int
     * }
     */
    public function probe(User $user): array
    {
        $user->loadMissing('groups');
        $shells = [];
        $total = 0;

        foreach ($this->orderedShells() as $shell) {
            $published = Lesson::query()
                ->where('course_id', $shell->id)
                ->where('is_published', true)
                ->count();
            $accessible = $this->accessibleLessons($user, $shell)->count();
            $total += $accessible;
            $shells[] = [
                'id' => (int) $shell->id,
                'slug' => (string) $shell->slug,
                'label' => $this->shellLabel($shell),
                'published' => $published,
                'accessible' => $accessible,
            ];
        }

        return [
            'user_id' => (int) $user->id,
            'flag' => $this->enabled(),
            'shells' => $shells,
            'accessible_total' => $total,
        ];
    }

    /**
     * Active Hindi shells plus predecessors, cycle-safe (H2442).
     *
     * @return Collection<int, Course>
     */
    public function orderedShells(): Collection
    {
        return $this->graph->orderedShells('hindi');
    }

    public function shellLabel(Course $course): string
    {
        $title = (string) $course->title;
        if ($title !== '' && preg_match('/гр\.?\s*(\d+)/ui', $title, $m)) {
            return 'гр. '.$m[1];
        }
        if ($title !== '' && preg_match('/групп[аыеу]\s*(\d+)/ui', $title, $m)) {
            return 'гр. '.$m[1];
        }

        return $title !== '' ? $title : 'курс '.$course->id;
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function accessibleLessons(User $user, Course $shell): Collection
    {
        $inGroup = $shell->relationLoaded('groups')
            ? $shell->groups->pluck('id')->intersect($user->groups->pluck('id'))->isNotEmpty()
            : $shell->groups()->whereIn('groups.id', $user->groups->pluck('id'))->exists();

        $grantedIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $shell->id);
        if (! $inGroup && $grantedIds === []) {
            return collect();
        }

        $keys = array_values(array_unique(array_merge(
            $this->diagnostics->ownedKeys($user, (int) $shell->id),
            $this->club->extraTariffKeys($user, $shell),
        )));

        return Lesson::query()
            ->where('course_id', $shell->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (Lesson $lesson) use ($user, $inGroup, $grantedIds, $keys, $shell): bool {
                $granted = in_array($lesson->id, $grantedIds, true);
                $visible = $inGroup && $lesson->isVisibleToGroupsOf($user);
                if (! $visible && ! $granted) {
                    return false;
                }

                return $this->diagnostics->isLessonAccessible(
                    $lesson,
                    $keys,
                    $user,
                    (int) $shell->id,
                );
            })
            ->values();
    }
}
