<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Ход курсов» (админка): на каком занятии и в каком блоке сейчас каждый поток.
 *
 * Всё берётся ИЗ РАСПИСАНИЯ, а не из `course_blocks`: даты блоков в карточке
 * курса заводят руками, и у части курсов их нет или они устарели (блок без
 * даты конца давал «3-е из 61»). Номер занятия — тег «(#13, 08.09.26)» в
 * титуле строки расписания ({@see LessonSeriesInfo}); блок — по BLOCK_SIZE
 * занятий подряд, как его считают TemplateRenderer ({BLOCK}/{BN}) и
 * напоминание об оплате блока в SlotNoticeService.
 *
 * Нет тега — номер по порядку живых занятий потока в календаре, строка
 * помечается `numbered_by = order`. Обзорные занятия не считаются.
 */
final class StreamProgressReport
{
    /** Занятий в блоке — тот же размер, что у TemplateRenderer и SlotNoticeService. */
    public const BLOCK_SIZE = 4;

    /** Поток «идёт», если у него есть занятие в пределах стольких дней от сегодня. */
    public const WINDOW_DAYS = 35;

    public const BY_TAG = 'tag';

    public const BY_ORDER = 'order';

    /**
     * @return array<int, array{
     *     key: string, course_id: int|null, course_title: string, group_id: int|null, group_name: string|null,
     *     number: int, total: int|null, block: int, in_block: int, numbered_by: string,
     *     next: Schedule|null, last_past: Schedule|null
     * }>
     */
    public function rows(?CarbonInterface $now = null): array
    {
        $now = $now !== null ? Carbon::instance($now) : now();

        $recent = $this->lessons()
            ->with(['course', 'group.courses'])
            ->whereBetween('start', [$now->copy()->subDays(self::WINDOW_DAYS), $now->copy()->addDays(self::WINDOW_DAYS)])
            ->orderBy('start')
            ->get();

        if ($recent->isEmpty()) {
            return [];
        }

        $history = $this->history($recent);

        return $recent
            ->groupBy(fn (Schedule $s) => self::streamKey($s))
            ->map(fn (Collection $sessions, string $key) => $this->row($key, $sessions, $history->get($key, collect()), $now))
            ->filter()
            ->sortBy(fn (array $row) => mb_strtolower($row['course_title'].' '.$row['group_name']))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Schedule>  $sessions  занятия потока в окне, по дате
     * @param  Collection<int, Schedule>  $history  все занятия потока, включая мягко удалённые
     */
    private function row(string $key, Collection $sessions, Collection $history, Carbon $now): ?array
    {
        $next = $sessions->first(fn (Schedule $s) => self::endOf($s)->gte($now));
        $lastPast = $sessions->filter(fn (Schedule $s) => self::endOf($s)->lt($now))->last();
        $ref = $next ?? $lastPast;

        if ($ref === null) {
            return null;
        }

        $number = LessonSeriesInfo::number($ref->title);
        $numberedBy = self::BY_TAG;

        if ($number === null) {
            $number = $history
                ->filter(fn (Schedule $s) => $s->deleted_at === null && $s->start->lte($ref->start))
                ->count();
            $numberedBy = self::BY_ORDER;
        }

        $tags = $history->map(fn (Schedule $s) => LessonSeriesInfo::number($s->title))->filter();
        $total = $tags->isNotEmpty()
            ? (int) $tags->max()
            : ($numberedBy === self::BY_ORDER ? $history->whereNull('deleted_at')->count() : null);

        $group = $ref->group;
        $course = $ref->course ?? $group?->courses->first();

        return [
            'key' => $key,
            'course_id' => $course?->id,
            'course_title' => (string) ($course?->title ?? $group?->name ?? $ref->title),
            'group_id' => $group?->id,
            'group_name' => $group?->name,
            'number' => $number,
            'total' => $total,
            'block' => (int) ceil($number / self::BLOCK_SIZE),
            'in_block' => (($number - 1) % self::BLOCK_SIZE) + 1,
            'numbered_by' => $numberedBy,
            'next' => $next,
            'last_past' => $lastPast,
        ];
    }

    /**
     * Вся история затронутых потоков одним запросом — для общего числа занятий
     * (максимум тега, с мягко удалёнными, как LessonSeriesInfo::totalForGroup)
     * и номера по порядку, когда тега нет.
     *
     * @param  Collection<int, Schedule>  $recent
     * @return Collection<string, Collection<int, Schedule>>
     */
    private function history(Collection $recent): Collection
    {
        $groupIds = $recent->pluck('group_id')->filter()->unique()->values();
        $courseIds = $recent->whereNull('group_id')->pluck('course_id')->filter()->unique()->values();

        return $this->lessons()
            ->withTrashed()
            ->where(function (Builder $q) use ($groupIds, $courseIds) {
                $q->whereIn('group_id', $groupIds)
                    ->orWhere(fn (Builder $w) => $w->whereNull('group_id')->whereIn('course_id', $courseIds));
            })
            ->orderBy('start')
            ->get(['id', 'group_id', 'course_id', 'title', 'start', 'deleted_at'])
            ->groupBy(fn (Schedule $s) => self::streamKey($s));
    }

    /** @return Builder<Schedule> */
    private function lessons(): Builder
    {
        return Schedule::query()
            ->where('is_overview', 0)
            ->whereNotNull('start');
    }

    /** Поток — группа; занятия без группы — общекурсовой поток. */
    private static function streamKey(Schedule $s): string
    {
        return $s->group_id !== null ? 'g'.$s->group_id : 'c'.$s->course_id;
    }

    private static function endOf(Schedule $s): Carbon
    {
        return $s->end ?? $s->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS);
    }
}
