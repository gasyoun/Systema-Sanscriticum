<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Services\Payments\TochkaBalanceService;
use Illuminate\Console\Command;

/**
 * Dry-run Tochka ClosingAvailable. Asserts money tables did not move.
 */
class TochkaBalanceCommand extends Command
{
    protected $signature = 'tochka:balance {--json : Machine-readable snapshot}';

    protected $description = 'Read Tochka ClosingAvailable (no writes to payments or teacher_payouts)';

    public function handle(TochkaBalanceService $svc): int
    {
        $payoutsBefore = TeacherPayout::query()->count();
        $paymentsBefore = Payment::query()->count();

        $snap = $svc->snapshot();

        $payoutsAfter = TeacherPayout::query()->count();
        $paymentsAfter = Payment::query()->count();
        $moved = $payoutsAfter !== $payoutsBefore || $paymentsAfter !== $paymentsBefore;

        $payload = $snap + [
            'teacher_payouts_count' => $payoutsAfter,
            'payments_count' => $paymentsAfter,
            'money_tables_moved' => $moved,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            if (! $snap['ok']) {
                $this->error($snap['error'] ?? 'failed');
            } else {
                $this->info('ClosingAvailable '.$snap['closing_total'].' RUB');
            }
            $this->line('money_tables_moved='.($moved ? 'yes' : 'no'));
        }

        return $moved ? self::FAILURE : self::SUCCESS;
    }
}
