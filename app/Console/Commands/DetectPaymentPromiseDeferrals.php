<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Promises\PaymentPromiseDeferralDetector;
use Illuminate\Console\Command;

class DetectPaymentPromiseDeferrals extends Command
{
    protected $signature = 'promises:detect-deferrals';

    protected $description = 'Найти в переписке (веб-чат + TG-support) просьбы об отсрочке оплаты и завести PaymentPromiseSuggestion для куратора. Не создаёт PaymentPromise само.';

    public function handle(PaymentPromiseDeferralDetector $detector): int
    {
        $result = $detector->run();

        $this->info("Детектор отсрочек: просканировано {$result['scanned']}, создано предложений {$result['created']}.");

        return self::SUCCESS;
    }
}
