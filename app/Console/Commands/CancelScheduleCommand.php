<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Schedule;
use App\Services\Schedule\LessonSeriesInfo;
use App\Services\Schedule\ScheduleMover;
use App\Services\Telegram\CancelNoticeRenderer;
use App\Services\Telegram\CancelReasonResolver;
use Illuminate\Console\Command;

/**
 * H4199: отмена занятия каскадом +7 дней из консоли — агентский/операторский
 * канал рядом с reply-командой «Отмена занятия» (бот) и кнопкой в админке.
 * Тот же ScheduleMover::cancelAndShiftWeek, что и оба других пути.
 *
 * MG 08-09: после каскада в чат группы уходит то же подтверждение, что и
 * бот-команде (причина, «N-е из M», последнее занятие). Причина — опция
 * --reason (ровно одна главная), иначе авто: отпуск преподавателя/каникулы.
 *
 * Пример: php artisan schedule:cancel 606 --reason="отсутствие кворума"
 */
class CancelScheduleCommand extends Command
{
    protected $signature = 'schedule:cancel {id : ID строки расписания} {--reason= : Главная причина отмены — уходит в подтверждение чату группы}';

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

        $label = $this->lessonLabel($schedule);
        $reason = CancelReasonResolver::resolve($schedule, $this->option('reason') ?: null);

        $oldStart = $schedule->start->copy();
        $shifted = $mover->cancelAndShiftWeek($schedule);

        $this->info(sprintf(
            'Готово: сдвинуто занятий %d. Слот %s освобождён, это занятие теперь %s.',
            $shifted,
            $oldStart->format('d.m.Y H:i'),
            $schedule->fresh()->start?->format('d.m.Y H:i'),
        ));

        $chatId = $schedule->group?->telegram_chat_id;
        if (is_string($chatId) && $chatId !== '') {
            SendZapisiBotMessageJob::dispatch($chatId, CancelNoticeRenderer::build(
                $label,
                $schedule->fresh()->start,
                LessonSeriesInfo::number($schedule->title),
                LessonSeriesInfo::totalForGroup((int) $schedule->group_id),
                LessonSeriesInfo::lastRowForGroup((int) $schedule->group_id)?->start,
                $reason,
            ));
            $this->info('Подтверждение отмены отправлено в чат группы.');
        }

        return self::SUCCESS;
    }

    /** Заголовок занятия без хвостовой даты «(#13, 06.09.26)» — она устареет после сдвига. */
    private function lessonLabel(Schedule $schedule): string
    {
        $title = trim((string) ($schedule->title ?: 'Занятие'));
        $stripped = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $title));

        return $stripped !== '' ? $stripped : $title;
    }
}
