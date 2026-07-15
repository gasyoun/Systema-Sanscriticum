<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Rq4Participant;
use App\Models\ScheduledReminder;
use Illuminate\Console\Command;

/**
 * H987 -- RQ4 study, +4-week retention reminder. Reuses the existing
 * ScheduledReminder infrastructure (H187) rather than building a new
 * notification channel -- this command's only job is to find due
 * participants and queue one ScheduledReminder each; `reminders:send-due`
 * (already scheduled) delivers it across whatever channels the user has.
 *
 * Idempotent: a participant is only ever queued once (retention_reminder_sent_at
 * is set immediately after queuing, before delivery -- the send itself is
 * ScheduledReminder's concern, not this command's).
 *
 *   php artisan rq4:send-retention-reminders
 */
class SendRq4RetentionReminders extends Command
{
    protected $signature = 'rq4:send-retention-reminders';

    protected $description = 'Queue a ScheduledReminder for RQ4 participants whose 4-week retention test is due';

    public function handle(): int
    {
        if (! config('features.rq4_study', false)) {
            $this->warn('features.rq4_study is OFF — nothing to do.');

            return self::SUCCESS;
        }

        $due = Rq4Participant::query()->retentionReminderDue()->get();

        $queued = 0;
        foreach ($due as $participant) {
            ScheduledReminder::create([
                'user_id' => $participant->user_id,
                'created_by' => null,
                'message' => 'Прошло 4 недели с прошлого теста в исследовании про изучение рядов/типов корней — '.
                    'загляните, пожалуйста, пройти финальный короткий тест: '.
                    route('rq4.diagnostic', ['phase' => 'retention_test']),
                'to_telegram' => true,
                'to_vk' => true,
                'to_email' => true,
                'scheduled_for' => now(),
            ]);

            $participant->update(['retention_reminder_sent_at' => now()]);
            $queued++;
        }

        $this->info("Queued {$queued} RQ4 retention reminder(s).");

        return self::SUCCESS;
    }
}
