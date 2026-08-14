<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Activity\StorefrontAnalytics;
use Illuminate\Console\Command;

/**
 * 7-day (or N-day) count query for H2762 storefront experiments.
 *
 *   php artisan shop:flagship-experiments
 *   php artisan shop:flagship-experiments --days=7
 *   php artisan shop:flagship-experiments --days=30 --json
 */
class FlagshipExperimentsReportCommand extends Command
{
    protected $signature = 'shop:flagship-experiments
                            {--days=7 : Window length in days}
                            {--json : Machine-readable JSON on stdout}';

    protected $description = 'H2762 storefront experiment counts (next_step_click / card_impression / sample_play / begin_checkout)';

    public function handle(StorefrontAnalytics $analytics): int
    {
        $days = max(1, (int) $this->option('days'));
        $report = $analytics->countWindow($days);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Flagship experiments ({$report['flagship']}, last {$report['days']}d)");
        $this->line("Window: {$report['from']} → {$report['to']}");
        $this->newLine();

        if ($report['rows'] === []) {
            $this->line('No storefront_events in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['Experiment', 'Event', 'Variant', 'n'],
            array_map(fn (array $row) => [
                $row['experiment'],
                $row['event_name'],
                $row['variant'] ?? '—',
                $row['n'],
            ], $report['rows']),
        );

        return self::SUCCESS;
    }
}
