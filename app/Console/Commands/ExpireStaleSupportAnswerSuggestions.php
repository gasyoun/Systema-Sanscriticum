<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAnswerSuggestion;
use Illuminate\Console\Command;

class ExpireStaleSupportAnswerSuggestions extends Command
{
    protected $signature = 'support:expire-stale-answer-suggestions';

    protected $description = 'Пометить expired pending-черновики FAQ-ответов, провисевшие без действия куратора дольше support.answer_suggestion_expiry_days (не удаляет — для аудита).';

    public function handle(): int
    {
        $days = (int) config('support.answer_suggestion_expiry_days', 14);

        $count = SupportAnswerSuggestion::query()
            ->pending()
            ->where('created_at', '<=', now()->subDays($days))
            ->update(['status' => SupportAnswerSuggestion::STATUS_EXPIRED]);

        $this->info("Просрочено черновиков FAQ-ответов: {$count}.");

        return self::SUCCESS;
    }
}
