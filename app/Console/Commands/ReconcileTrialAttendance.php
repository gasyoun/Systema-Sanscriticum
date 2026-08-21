<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\TrialBookingService;
use Illuminate\Console\Command;

/**
 * H3247 — Zoom attendance → trial Deal outcome. Draft FollowUpTask only.
 * Never writes no_show on unmatched email. Flag-gated crm_trial_booking.
 */
class ReconcileTrialAttendance extends Command
{
    protected $signature = 'crm:reconcile-trial-attendance {--deal= : Restrict to one Deal id}';

    protected $description = 'Сверяет пробные сделки с webinar_attendances (черновик FollowUpTask, без авто-отправки)';

    public function handle(TrialBookingService $bookings): int
    {
        if (! config('features.crm_trial_booking')) {
            $this->info('CRM_TRIAL_BOOKING выключен — пропуск.');

            return self::SUCCESS;
        }

        $dealId = $this->option('deal');
        $touched = $bookings->reconcileAttendance($dealId !== null ? (int) $dealId : null);
        $this->info("Пробных сделок обработано: {$touched}");

        return self::SUCCESS;
    }
}
