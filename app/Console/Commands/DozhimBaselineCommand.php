<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reports\OrderPaymentConversionService;
use Illuminate\Console\Command;

/**
 * NOBORING Wave 0 baseline: order→pay (rate A) + lead→paid (rate B).
 *
 * Roadmap: docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md Wave 0.
 * Reuses OrderPaymentConversionService (H262) — does not re-derive the cohort filter.
 *
 * Usage:
 *   php artisan dozhim:baseline
 *   php artisan dozhim:baseline --days=30 --days=90
 *   php artisan dozhim:baseline --json
 */
class DozhimBaselineCommand extends Command
{
    protected $signature = 'dozhim:baseline
                            {--days=* : Window length(s) in days (default: 30 and 90)}
                            {--json : Machine-readable JSON on stdout}';

    protected $description = 'NOBORING dozhim baseline: rate A (order→pay) and rate B (lead→converted) for last N days';

    public function handle(OrderPaymentConversionService $service): int
    {
        $daysOpt = $this->option('days');
        $windows = is_array($daysOpt) && $daysOpt !== []
            ? array_map(static fn ($d): int => max(1, (int) $d), $daysOpt)
            : [30, 90];

        $report = $service->dozhimBaseline(windowsDays: $windows);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('NOBORING dozhim baseline (as of '.$report['as_of'].')');
        $this->line($report['zayavka_definition']);
        $this->newLine();

        $rows = [];
        foreach ($report['windows'] as $w) {
            $a = $w['rate_a'];
            $b = $w['rate_b'];
            $rows[] = [
                $w['days'],
                $a['orders'],
                $a['paid'],
                $a['pending'],
                $a['lost'],
                $a['conversion_pct'] === null ? '—' : $a['conversion_pct'].'%',
                $b['leads'],
                $b['converted'],
                $b['conversion_pct'] === null ? '—' : $b['conversion_pct'].'%',
            ];
        }

        $this->table(
            ['Days', 'A orders', 'A paid', 'A pending', 'A lost', 'Rate A', 'B leads', 'B converted', 'Rate B'],
            $rows
        );

        $this->line('Rate A = paid / (paid+unpaid eligible Orders as Payments). Rate B = converted_at Leads / Leads in window.');

        return self::SUCCESS;
    }
}
