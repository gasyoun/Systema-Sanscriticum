<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Services\Schedule\ScheduleMover;
use Illuminate\Console\Command;

/**
 * H4199: отмена занятия каскадом +7 дней из консоли — агентский/операторский
 * канал рядом с reply-командой «Отмена занятия» (бот) и кнопкой в админке.
 * Тот же ScheduleMover::cancelAndShiftWeek, что и оба других пути.
 *
 * Пример: php artisan schedule:cancel 606
 */
class CancelScheduleCommand extends Command
{
    protected $signature = 'schedule:cancel {id : ID строки расписания}';

    protected $description = 'Отменить занятие: это и все последующие занятия группы сдвигаются на +7 дней (ScheduleMover::cancelAndShiftWeek)';

    public function handle(): int
    {
        $schedule = Schedule::with('group')->find((int) $this->argument('id'));

        if ($schedule === null) {
            $this->error('Занятие не найдено.');

            return self::FAILURE;
        }

        if ($schedule->group_id === null || $schedule->start === null) {
            $this->error('У занятия нет group_id или start — каскад невозможен (см. ScheduleMover).');

            return self::FAILURE;
        }

        $mover = app(ScheduleMover::class);

        $this->info(sprintf(
            'Группа «%s», занятие #%d на %s — под каскад попадёт %d занят.',
            $schedule->group?->name ?? '—',
            $schedule->id,
            $schedule->start->format('d.m.Y H:i'),
            $mover->countChain($schedule),
        ));

        $oldStart = $schedule->start->copy();
        $shifted = $mover->cancelAndShiftWeek($schedule);

        $this->info(sprintf(
            'Готово: сдвинуто занятий %d. Слот %s освобождён, это занятие теперь %s.',
            $shifted,
            $oldStart->format('d.m.Y H:i'),
            $schedule->fresh()->start?->format('d.m.Y H:i'),
        ));

        return self::SUCCESS;
    }
}
