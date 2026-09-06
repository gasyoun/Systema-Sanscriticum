<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Перенос и отмена занятий в расписании.
 *
 * Два режима:
 *  - reschedule: двигает только выбранное занятие;
 *  - cancelAndShiftWeek: освобождает слот и сдвигает это + все последующие
 *    занятия той же группы (group_id, start >= текущего) на +7 дней.
 */
final class ScheduleMover
{
    /**
     * Перенос одного занятия на новую дату/время.
     * Если $newEnd не задан, сохраняется прежняя длительность (при наличии end).
     */
    public function reschedule(Schedule $schedule, Carbon $newStart, ?Carbon $newEnd = null): Schedule
    {
        if ($newEnd === null && $schedule->end !== null && $schedule->start !== null) {
            $minutes = $schedule->start->diffInMinutes($schedule->end);
            $newEnd = $newStart->copy()->addMinutes(max(1, (int) $minutes));
        }

        $schedule->start = $newStart;
        $schedule->end = $newEnd;
        // Обычный save: model updating() сбросит lifecycle; observer уйдёт в n8n (одно событие).
        $schedule->save();

        return $schedule->refresh();
    }

    /**
     * Отмена занятия: this + subsequent той же группы получают +7 дней.
     * Слот исходной даты освобождается. ID строк сохраняются.
     *
     * @return int число сдвинутых записей
     */
    public function cancelAndShiftWeek(Schedule $schedule): int
    {
        if ($schedule->group_id === null) {
            throw new InvalidArgumentException(
                'Отмена с каскадом доступна только для занятий с привязанной группой.'
            );
        }

        if ($schedule->start === null) {
            throw new InvalidArgumentException('У занятия не задана дата начала.');
        }

        return (int) DB::transaction(function () use ($schedule): int {
            $chain = $this->chainQuery($schedule)->lockForUpdate()->get();

            foreach ($chain as $row) {
                $this->shiftRowByWeek($row);
            }

            return $chain->count();
        });
    }

    /**
     * Сколько записей попадёт под каскад (для confirmation-modal).
     */
    public function countChain(Schedule $schedule): int
    {
        if ($schedule->group_id === null || $schedule->start === null) {
            return 0;
        }

        return $this->chainQuery($schedule)->count();
    }

    /**
     * H4253: отмена ОДНОГО занятия БЕЗ сдвига цепочки (датированная команда
     * «Отмена 23.09» в чате группы). Строка мягко удаляется; остальные
     * занятия группы остаются на своих датах, слот просто исчезает.
     */
    public function cancelSingle(Schedule $schedule): bool
    {
        if ($schedule->group_id === null) {
            throw new InvalidArgumentException(
                'Отмена без сдвига доступна только для занятий с привязанной группой.'
            );
        }

        if ($schedule->start === null) {
            throw new InvalidArgumentException('У занятия не задана дата начала.');
        }

        return (bool) DB::transaction(function () use ($schedule): bool {
            return $schedule->delete();
        });
    }

    /**
     * Цепочка: та же группа, start >= выбранного (включая само занятие).
     *
     * @return Builder<Schedule>
     */
    private function chainQuery(Schedule $schedule)
    {
        return Schedule::query()
            ->where('group_id', $schedule->group_id)
            ->where('start', '>=', $schedule->start)
            ->orderBy('start');
    }

    private function shiftRowByWeek(Schedule $row): void
    {
        $oldStart = $row->start?->copy();
        $newStart = $row->start?->copy()->addWeek();
        $newEnd = $row->end?->copy()->addWeek();

        if ($newStart === null) {
            return;
        }

        $row->start = $newStart;
        $row->end = $newEnd;

        if ($oldStart !== null) {
            $this->rewriteEmbeddedDates($row, $oldStart, $newStart);
        }

        $this->resetLifecycle($row);

        // saveQuietly: каскад 20–60 save() иначе бьёт n8n webhook N раз с timeout.
        // Lifecycle сбрасываем явно — quietly обходит model updating().
        $row->saveQuietly();
    }

    /**
     * Шаблон генератора кладёт {DATE} как d.m.y в title/description —
     * после сдвига даты в тексте устаревают.
     */
    private function rewriteEmbeddedDates(Schedule $row, Carbon $oldStart, Carbon $newStart): void
    {
        $replacements = [
            $oldStart->format('d.m.y') => $newStart->format('d.m.y'),
            $oldStart->format('d.m.Y') => $newStart->format('d.m.Y'),
        ];

        foreach (['title', 'description'] as $field) {
            $value = $row->{$field};
            if (! is_string($value) || $value === '') {
                continue;
            }
            $row->{$field} = strtr($value, $replacements);
        }
    }

    /**
     * Сброс меток «уже напомнили» + per-occurrence Zoom (слот сменил дату).
     * link / zoom_meeting_id не трогаем — единая ссылка курса.
     */
    private function resetLifecycle(Schedule $row): void
    {
        $row->reminded_at = null;
        $row->absent_notified_at = null;
        $row->group_link_posted_at = null;
        $row->zapisi_reminded_at = null;
        $row->zoom_occurrence_uuid = null;
        $row->zoom_recording_url = null;
        $row->zoom_recording_received_at = null;
    }
}
