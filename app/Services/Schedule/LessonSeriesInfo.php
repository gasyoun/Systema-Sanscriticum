<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Schedule;
use Carbon\CarbonInterface;

/**
 * MG 08-09: подтверждение отмены озвучивает «какое оно по счету, например
 * 15/16 — 15-е из всего 16». Номер занятия хранится ТОЛЬКО в теге титула
 * «(#13, 08.09.26)» (TemplateRenderer, плейсхолдер {N}) — отдельной колонки
 * нет. Общее число потока = максимум тега по всем строкам группы, включая
 * мягко удалённые (одиночная отмена не должна занижать M).
 */
final class LessonSeriesInfo
{
    /** Номер из тега «(#13, …)» титула; null — тег не найден. */
    public static function number(?string $title): ?int
    {
        if ($title === null || preg_match('/\(#(\d+),/u', $title, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** Всего занятий в потоке (max тега по группе, с мягко удалёнными); null — тегов нет. */
    public static function totalForGroup(int $groupId): ?int
    {
        $numbers = Schedule::query()
            ->withTrashed()
            ->where('group_id', $groupId)
            ->where('is_overview', 0)
            ->whereNotNull('title')
            ->pluck('title')
            ->map(self::number(...))
            ->filter()
            ->values();

        return $numbers->isEmpty() ? null : (int) $numbers->max();
    }

    /** Последняя (по дате) живая строка потока — для строки «Последнее, M-е занятие…». */
    public static function lastRowForGroup(int $groupId): ?Schedule
    {
        return Schedule::query()
            ->where('group_id', $groupId)
            ->where('is_overview', 0)
            ->whereNotNull('start')
            ->orderByDesc('start')
            ->first();
    }

    /** Ближайшее будущее занятие потока строго после $after; null — поток закончился. */
    public static function nextRowAfter(int $groupId, CarbonInterface $after): ?Schedule
    {
        return Schedule::query()
            ->where('group_id', $groupId)
            ->where('is_overview', 0)
            ->whereNotNull('start')
            ->where('start', '>', $after)
            ->orderBy('start')
            ->first();
    }
}
