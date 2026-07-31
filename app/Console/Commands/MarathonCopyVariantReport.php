<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonLandingCopyVariantEvent;
use App\Support\MarathonLandingCopySplit;
use Illuminate\Console\Command;

/**
 * H2010 — the numbers MG's 31-07-2026 ruling asks for after 1 November:
 * per-variant impressions, leads, conversion rate from the real A/B split
 * (MarathonLandingCopySplit). Reports only -- never declares a winner or
 * writes MARATHON_LANDING_COPY_VARIANT; a human reads this and decides.
 */
final class MarathonCopyVariantReport extends Command
{
    protected $signature = 'marathon:copy-variant-report';

    protected $description = 'Per-variant impressions/leads/conversion for the H2010 marathon landing copy A/B test';

    public function handle(): int
    {
        $isActive = MarathonLandingCopySplit::isExperimentActive();
        $until = (string) config('marathon_landing_copy.ab_test_until');
        $this->info($isActive
            ? "Experiment live -- new visitors still being split (cutoff {$until})."
            : "Experiment closed (cutoff {$until} reached) -- new visitors now get the config default variant.");

        $rows = [];
        foreach (['a', 'b'] as $variant) {
            $impressions = MarathonLandingCopyVariantEvent::query()
                ->where('variant', $variant)
                ->where('event_type', 'impression')
                ->count();
            $leads = MarathonLandingCopyVariantEvent::query()
                ->where('variant', $variant)
                ->where('event_type', 'lead')
                ->count();
            $rate = $impressions > 0 ? round($leads / $impressions * 100, 2) : null;

            $rows[] = [
                strtoupper($variant),
                $impressions,
                $leads,
                $rate === null ? 'n/a' : "{$rate}%",
            ];
        }

        $this->table(['Variant', 'Impressions', 'Leads', 'Conversion'], $rows);
        $this->comment('This command reports only -- it never picks a winner or flips MARATHON_LANDING_COPY_VARIANT.');

        return self::SUCCESS;
    }
}
