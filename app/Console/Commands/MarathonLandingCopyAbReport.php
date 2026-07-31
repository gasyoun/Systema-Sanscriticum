<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Console\Command;

/**
 * H1067 — reads the live copy-variant split (MARATHON_LANDING_COPY_VARIANT=ab)
 * back out: registrations per arm and how many of those converted to the
 * paid track's confirmed payment, per arm. Read-only — writes nothing.
 * `landing_copy_arm` is set once per Lead in MarathonController::register(),
 * see App\Support\MarathonLandingCopy::resolveArmForRequest().
 */
final class MarathonLandingCopyAbReport extends Command
{
    protected $signature = 'marathon:landing-copy-ab-report';

    protected $description = 'Registrations + paid-track conversion by marathon landing copy arm (a/b)';

    public function handle(): int
    {
        $landing = LandingPage::where('slug', config('marathon.landing_slug'))->first();
        if (! $landing) {
            $this->warn('No LandingPage row for the marathon slug yet — nothing to report.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach (['a', 'b'] as $arm) {
            $leadIds = Lead::where('landing_page_id', $landing->id)
                ->where('landing_copy_arm', $arm)
                ->pluck('id');

            $registered = $leadIds->count();
            $paidConfirmed = MarathonEnrollment::whereIn('lead_id', $leadIds)
                ->where('track', MarathonEnrollment::TRACK_PAID)
                ->get()
                ->filter(fn (MarathonEnrollment $e) => $e->isPaidConfirmed())
                ->count();

            $rows[] = [
                strtoupper($arm),
                $registered,
                $paidConfirmed,
                $registered > 0 ? round($paidConfirmed / $registered * 100, 1).'%' : '—',
            ];
        }

        $untagged = Lead::where('landing_page_id', $landing->id)
            ->whereNull('landing_copy_arm')
            ->count();

        $this->table(['Arm', 'Registered', 'Paid track confirmed', 'Conversion'], $rows);

        if ($untagged > 0) {
            $this->comment("{$untagged} lead(s) on this landing carry no arm — captured before this split shipped or via MARATHON_LANDING_COPY_VARIANT forced to a single letter.");
        }

        return self::SUCCESS;
    }
}
