<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\ZapisiClassSchedule;
use Illuminate\Console\Command;

/**
 * Track C (H164, D10): sends "class starts in ~1h" reminders for the
 * zapisi_ORSbot booking chat. Idempotent — safe to run every minute
 * (dedup via sent_at, same recipe as classes:remind-upcoming /
 * classes:post-group-link).
 */
class SendZapisiClassReminders extends Command
{
    protected $signature = 'zapisi:send-reminders {--minutes=60 : Lead time before class start}';

    protected $description = 'Sends "class starts in ~1h" reminders for @zapisi_ORSbot schedules, once per row.';

    public function handle(): int
    {
        if (! config('features.telegram_zapisi_bot')) {
            $this->info('Telegram Track C (zapisi bot) is disabled via TELEGRAM_ZAPISI_BOT_ENABLED — skipping.');

            return self::SUCCESS;
        }

        $lead = max(1, (int) $this->option('minutes'));

        $schedules = ZapisiClassSchedule::query()
            ->whereNull('sent_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes($lead)])
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            SendZapisiBotMessageJob::dispatch($schedule->chat_id, $this->renderText($schedule));
            $schedule->update(['sent_at' => now()]);
            $sent++;
        }

        $this->info("Zapisi reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    private function renderText(ZapisiClassSchedule $schedule): string
    {
        $time = $schedule->starts_at->format('H:i');
        $template = trim($schedule->message_template) !== ''
            ? $schedule->message_template
            : 'Занятие начнётся сегодня в {time}.';

        return str_replace('{time}', $time, $template);
    }
}
