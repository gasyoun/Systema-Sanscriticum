<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Services\TeacherWeeklyPayoutCalendarService;
use Illuminate\Console\Command;

/**
 * Dry-run ISO-week payout grid. Asserts money tables did not move.
 */
class TeacherWeeklyPayoutCalendarCommand extends Command
{
    protected $signature = 'teacher-payouts:week-calendar {--year= : ISO year} {--json : Machine-readable grid}';

    protected $description = 'Print weekly teacher payout due grid (no writes to payments or teacher_payouts)';

    public function handle(TeacherWeeklyPayoutCalendarService $svc): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $beforeP = TeacherPayout::query()->count();
        $beforePay = Payment::query()->count();

        $grid = $svc->grid($year);

        $afterP = TeacherPayout::query()->count();
        $afterPay = Payment::query()->count();
        $moved = $afterP !== $beforeP || $afterPay !== $beforePay || $grid['money_tables_moved'];
        $grid['teacher_payouts_count'] = $afterP;
        $grid['payments_count'] = $afterPay;
        $grid['money_tables_moved'] = $moved;

        if ($this->option('json')) {
            $this->line(json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $dueWeeks = collect($grid['weeks'])->filter(fn ($w) => count($w['due']) > 0);
            $this->info('year '.$year.' due weeks '.$dueWeeks->count());
            $this->line('money_tables_moved='.($moved ? 'yes' : 'no'));
        }

        return $moved ? self::FAILURE : self::SUCCESS;
    }
}
