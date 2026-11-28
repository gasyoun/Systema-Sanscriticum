<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramPoll;
use App\Services\Telegram\ZapisiPollService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Авто-закрытие опросов о присутствии и отправка итогов админам.
 *
 * За `attendance_poll_report_lead_minutes` минут до старта занятия (по умолчанию
 * за час, 60) закрывает идущий автоопрос (kind=attendance) этого занятия —
 * ZapisiPollService::close() останавливает опрос и шлёт итоги админам в личку
 * (ZapisiPollReporter); если «буду» меньше `attendance_poll_cancel_threshold`,
 * к итогам прикладывается кнопка «Отменить занятие».
 *
 * Занятия, которые уже начались, не трогает — отмена после старта бессмысленна;
 * такие опросы остаются висеть до ручного закрытия. Идемпотентность даёт
 * `status=closed` (повторный прогон отправленный опрос уже не видит). Один
 * сбойный опрос не глушит остальные (как у classes:post-attendance-poll).
 */
class CloseAttendancePolls extends Command
{
    protected $signature = 'classes:close-attendance-polls';

    protected $description = 'Закрывает автоопросы о присутствии перед занятием и шлёт итоги админам.';

    public function handle(ZapisiPollService $polls): int
    {
        if (! config('features.attendance_poll_report_admins', true)) {
            $this->info('Итоги автоопросов выключены (ATTENDANCE_POLL_REPORT_ADMINS) — пропуск.');

            return self::SUCCESS;
        }

        $lead = max(1, (int) config('features.attendance_poll_report_lead_minutes', 1440));

        $due = TelegramPoll::query()
            ->where('kind', TelegramPoll::KIND_ATTENDANCE)
            ->where('status', TelegramPoll::STATUS_SENT)
            ->whereNotNull('schedule_start')
            ->where('schedule_start', '>', now())
            ->where('schedule_start', '<=', now()->addMinutes($lead))
            ->get();

        $closed = 0;
        foreach ($due as $poll) {
            try {
                $polls->close($poll);
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('classes:close-attendance-polls: close failed', [
                    'poll_id' => $poll->id,
                    'schedule_id' => $poll->schedule_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Закрыто автоопросов о присутствии: {$closed}.");

        return self::SUCCESS;
    }
}
