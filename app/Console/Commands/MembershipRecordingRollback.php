<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class MembershipRecordingRollback extends Command
{
    protected $signature = 'membership:recording-rollback {--assert-off : Fail unless all recording gates are off}';

    protected $description = 'Print and verify the recording-gate rollback without touching payments or course groups';

    public function handle(): int
    {
        $flags = [
            'MEMBERSHIP_RECORDING_ENFORCE' => (bool) config('features.membership_recording_enforce'),
            'MEMBERSHIP_RECORDING_PILOT' => (bool) config('features.membership_recording_pilot'),
            'MEMBERSHIP_RECORDING_SHADOW' => (bool) config('features.membership_recording_shadow'),
        ];

        foreach ($flags as $name => $on) {
            $this->line($name.'='.($on ? 'ON' : 'OFF'));
        }
        $this->info('Rollback: set all three flags=false, then php artisan config:clear. No payment, period, group, lesson or course row is changed.');

        return $this->option('assert-off') && in_array(true, $flags, true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
