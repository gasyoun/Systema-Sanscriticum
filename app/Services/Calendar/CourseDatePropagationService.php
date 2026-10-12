<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CourseBlock;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Каскад «правка дат блока курса → сдвиг занятий» (Google Calendar Phase 4,
 * docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md §6/§7, H6369).
 *
 * Правило сдвига — **translate** (решение MG 10-10-2026, «грилл календарей»):
 * каждое БУДУЩЕЕ занятие курса сдвигается на ту же дельту, на которую
 * сдвинулась граница блока (starts_at — первичная, при её неизменности —
 * ends_at). Интервалы между занятиями сохраняются.
 *
 * - прошедшие занятия (start < now) не трогаются;
 * - сдвиг идёт через Eloquent save(), поэтому штатные хуки Schedule
 *   (сброс reminded_at / уведомлений к новому времени, n8n-синк) отрабатывают;
 * - студенческий ICS-фид отдельной правки не требует: IcsFeedBuilder читает
 *   Schedule live;
 * - уведомления группам и перенос Zoom-встреч — вне скоупа (Phase 3-каскад).
 */
class CourseDatePropagationService
{
    /**
     * @return int количество сдвинутых будущих занятий (0 — если даты блока
     *             не менялись или дельта не вычислима)
     */
    public function propagate(CourseBlock $block): int
    {
        $boundary = $this->changedBoundary($block);

        if ($boundary === null) {
            return 0;
        }

        $old = $block->getOriginal($boundary);
        $new = $block->{$boundary};

        if ($old === null || $new === null) {
            // Нельзя перевести занятия относительно границы, которой не было:
            // «блоку впервые поставили дату» — сдвигать нечего, логируем и выходим.
            Log::info('course_block.date_propagation.skipped_non_translatable', [
                'block_id' => $block->id,
                'course_id' => $block->course_id,
                'boundary' => $boundary,
                'reason' => 'null-boundary',
            ]);

            return 0;
        }

        $deltaSeconds = $new->getTimestamp() - Carbon::parse($old)->getTimestamp();

        if ($deltaSeconds === 0) {
            return 0;
        }

        $now = now();
        $nowMs = $now->getTimestamp();

        $future = Schedule::query()
            ->where('course_id', $block->course_id)
            ->whereNotNull('start')
            ->where('start', '>=', $now)
            ->orderBy('start')
            ->get();

        $skippedPast = Schedule::query()
            ->where('course_id', $block->course_id)
            ->where(function ($q) use ($now) {
                $q->whereNull('start')->orWhere('start', '<', $now);
            })
            ->count();

        foreach ($future as $schedule) {
            $schedule->start = $schedule->start->copy()->addSeconds($deltaSeconds);

            if ($schedule->end !== null) {
                $schedule->end = $schedule->end->copy()->addSeconds($deltaSeconds);
            }

            $schedule->save();
        }

        Log::info('course_block.date_propagation.translate', [
            'block_id' => $block->id,
            'course_id' => $block->course_id,
            'boundary' => $boundary,
            'delta_seconds' => $deltaSeconds,
            'shifted' => $future->count(),
            'skipped_past' => $skippedPast,
            'now_ms' => $nowMs,
        ]);

        return $future->count();
    }

    /**
     * Какую границу блока использовали для дельты: starts_at, если она
     * изменилась, иначе ends_at. null — даты не менялись.
     */
    private function changedBoundary(CourseBlock $block): ?string
    {
        if ($block->wasChanged('starts_at')) {
            return 'starts_at';
        }

        if ($block->wasChanged('ends_at')) {
            return 'ends_at';
        }

        return null;
    }
}
