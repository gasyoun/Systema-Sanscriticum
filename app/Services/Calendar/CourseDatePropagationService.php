<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CourseBlock;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Каскад «правка начала блока курса → сдвиг занятий» (Google Calendar Phase 4,
 * docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md §6/§7, H6369).
 *
 * Правило сдвига — **translate** (решение MG 10-10-2026, «грилл календарей»):
 * каждое БУДУЩЕЕ занятие курса сдвигается на ту же дельту, на которую
 * сдвинулась starts_at. Интервалы между занятиями сохраняются.
 * Изменение ends_at занятия не двигает: окончание блока не является командой
 * переносить расписание (инцидент курса 296, 20-10-2026).
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
     * @return int количество сдвинутых будущих занятий (0 — если starts_at
     *             не менялась или дельта не вычислима)
     */
    public function propagate(CourseBlock $block): int
    {
        if (! $block->wasChanged('starts_at')) {
            return 0;
        }

        $old = $block->getOriginal('starts_at');
        $new = $block->starts_at;

        if ($old === null || $new === null) {
            // Нельзя перевести занятия относительно границы, которой не было:
            // «блоку впервые поставили дату» — сдвигать нечего, логируем и выходим.
            Log::info('course_block.date_propagation.skipped_non_translatable', [
                'block_id' => $block->id,
                'course_id' => $block->course_id,
                'boundary' => 'starts_at',
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
            'boundary' => 'starts_at',
            'delta_seconds' => $deltaSeconds,
            'shifted' => $future->count(),
            'skipped_past' => $skippedPast,
            'now_ms' => $nowMs,
        ]);

        return $future->count();
    }
}
