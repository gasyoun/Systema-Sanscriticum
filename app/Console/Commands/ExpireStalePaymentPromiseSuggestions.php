<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentPromiseSuggestion;
use Illuminate\Console\Command;

class ExpireStalePaymentPromiseSuggestions extends Command
{
    protected $signature = 'promises:expire-stale-suggestions';

    protected $description = 'Pending PaymentPromiseSuggestion старше N дней → expired (аудит, не удаляет).';

    public function handle(): int
    {
        $days = (int) config('promise_suggestions.suggestion_expiry_days', 14);
        $cutoff = now()->subDays($days);

        $count = PaymentPromiseSuggestion::query()
            ->where('status', PaymentPromiseSuggestion::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => PaymentPromiseSuggestion::STATUS_EXPIRED]);

        $this->info("Истекло предложений отсрочки: {$count} (старше {$days} дн.).");

        return self::SUCCESS;
    }
}
