<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReminderSuggestion;
use Illuminate\Console\Command;

class ExpireStaleReminderSuggestions extends Command
{
    protected $signature = 'reminders:expire-stale-suggestions';

    protected $description = 'Пометить expired pending-предложения напоминаний, провисевшие без действия куратора дольше reminders.suggestion_expiry_days (не удаляет — для аудита).';

    public function handle(): int
    {
        $days = (int) config('reminders.suggestion_expiry_days', 14);

        $count = ReminderSuggestion::query()
            ->pending()
            ->where('created_at', '<=', now()->subDays($days))
            ->update(['status' => ReminderSuggestion::STATUS_EXPIRED]);

        $this->info("Просрочено предложений напоминаний: {$count}.");

        return self::SUCCESS;
    }
}
