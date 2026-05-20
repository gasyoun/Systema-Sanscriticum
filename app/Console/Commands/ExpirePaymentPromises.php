<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentPromise;
use Illuminate\Console\Command;

class ExpirePaymentPromises extends Command
{
    protected $signature = 'promises:expire';

    protected $description = 'Перевод просроченных обещаний оплатить в статус expired (запускать раз в день).';

    public function handle(): int
    {
        $count = PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', now()->toDateString())
            ->update(['status' => PaymentPromise::STATUS_EXPIRED]);

        $this->info("Помечено как expired: {$count}");

        return self::SUCCESS;
    }
}
