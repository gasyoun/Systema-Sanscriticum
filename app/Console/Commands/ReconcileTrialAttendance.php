<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deal;
use App\Services\Crm\TrialBookingService;
use Illuminate\Console\Command;

/**
 * H3247 — Zoom attendance → trial_outcome + draft FollowUpTask.
 * Scheduled in the same window as zoom:sync-attendance. Never sends mail.
 */
class ReconcileTrialAttendance extends Command
{
    protected $signature = 'crm:reconcile-trial-attendance';

    protected $description = 'Mark past trial Deals attended/no_show from Zoom, or leave booked + confirm task';

    public function handle(TrialBookingService $booking): int
    {
        if (! config('features.crm_trial_booking')) {
            $this->info('CRM_TRIAL_BOOKING off — skip.');

            return self::SUCCESS;
        }

        $attended = 0;
        $noShow = 0;
        $confirm = 0;
        $skipped = 0;

        $deals = Deal::query()
            ->open()
            ->trial()
            ->where('trial_outcome', Deal::TRIAL_OUTCOME_BOOKED)
            ->whereNotNull('schedule_id')
            ->with(['schedule', 'user', 'lead'])
            ->get();

        foreach ($deals as $deal) {
            $result = $booking->reconcileDeal($deal);
            match ($result['outcome']) {
                Deal::TRIAL_OUTCOME_ATTENDED => $attended++,
                Deal::TRIAL_OUTCOME_NO_SHOW => $noShow++,
                default => $result['task'] ? $confirm++ : $skipped++,
            };
        }

        $this->info("Trial reconcile: attended {$attended}, no_show {$noShow}, confirm {$confirm}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
