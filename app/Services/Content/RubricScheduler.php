<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Время выхода постов рубрики (28-10-2026). Пост без даты встаёт на
 * ближайший момент расписания, ещё не занятый другим постом рубрики; ручная
 * дата поста не трогается. reflow() — по кнопке и после смены расписания —
 * заново раскладывает невышедшие посты на моменты подряд.
 */
class RubricScheduler
{
    private const TZ = 'Europe/Moscow';

    /** Ближе этого к «сейчас» пост не ставим. */
    private const LEAD_MINUTES = 5;

    /**
     * Ближайшие моменты расписания строго позже $from.
     *
     * @return list<Carbon>
     */
    public function occurrences(ContentCalendarRubric $rubric, CarbonInterface $from, int $limit): array
    {
        $entries = $rubric->scheduleEntries();
        $perWeek = array_sum(array_map(static fn (array $e): int => count($e['days']), $entries));
        if ($perWeek === 0 || $limit <= 0) {
            return [];
        }

        $from = Carbon::instance($from)->setTimezone(self::TZ);
        $days = (intdiv($limit, $perWeek) + 2) * 7;
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->startOfDay()->addDays($i);
            foreach ($entries as $entry) {
                if (! in_array($day->dayOfWeekIso, $entry['days'], true)) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', $entry['time']));
                $at = $day->copy()->setTime($h, $m);
                if ($at->gt($from)) {
                    $out[$at->format('Y-m-d H:i')] = $at;
                }
            }
        }
        ksort($out);

        return array_slice(array_values($out), 0, $limit);
    }

    /** Ближайший свободный момент рубрики; null — у рубрики нет расписания. */
    public function nextFreeAt(ContentCalendarRubric $rubric, ?int $exceptSlotId = null, ?CarbonInterface $now = null): ?Carbon
    {
        $taken = $this->takenKeys($rubric, $exceptSlotId === null ? [] : [$exceptSlotId]);
        $from = Carbon::instance($now ?? Carbon::now(self::TZ))->addMinutes(self::LEAD_MINUTES);

        foreach ($this->occurrences($rubric, $from, count($taken) + 1) as $at) {
            if (! isset($taken[$at->format('Y-m-d H:i')])) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Невышедшие посты рубрики (scheduled, будущие или без даты, без
     * публикаций) — на моменты расписания подряд, в прежнем порядке.
     *
     * @return int сколько постов получили новое время
     */
    public function reflow(ContentCalendarRubric $rubric, ?CarbonInterface $now = null): int
    {
        $from = Carbon::instance($now ?? Carbon::now(self::TZ))->addMinutes(self::LEAD_MINUTES);

        $queue = $rubric->slots()
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '>', $from))
            ->whereDoesntHave('publications')
            ->orderByRaw('publish_at is null')
            ->orderBy('publish_at')
            ->orderBy('id')
            ->get();
        if ($queue->isEmpty()) {
            return 0;
        }

        $taken = $this->takenKeys($rubric, $queue->modelKeys());
        $free = array_values(array_filter(
            $this->occurrences($rubric, $from, $queue->count() + count($taken)),
            static fn (Carbon $at): bool => ! isset($taken[$at->format('Y-m-d H:i')]),
        ));

        $changed = 0;
        foreach ($queue->values() as $i => $slot) {
            $at = $free[$i] ?? null;
            if ($at === null) {
                break;
            }
            if ($slot->publish_at === null || ! $slot->publish_at->eq($at)) {
                $slot->forceFill(['publish_at' => $at, 'slot_date' => $at->toDateString()])->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Моменты, занятые постами рубрики (кроме отменённых и $except).
     *
     * @param  list<int>  $except
     * @return array<string, true>
     */
    private function takenKeys(ContentCalendarRubric $rubric, array $except): array
    {
        return $rubric->slots()
            ->where('status', '!=', ContentCalendarSlot::STATUS_CANCELED)
            ->whereNotNull('publish_at')
            ->when($except !== [], fn ($q) => $q->whereNotIn('id', $except))
            ->pluck('publish_at')
            ->mapWithKeys(fn ($at): array => [Carbon::parse($at)->setTimezone(self::TZ)->format('Y-m-d H:i') => true])
            ->all();
    }
}
