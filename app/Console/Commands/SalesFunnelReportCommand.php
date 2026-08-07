<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reports\SalesFunnelReportService;
use Illuminate\Console\Command;

/**
 * Sales funnel operator report (H2378).
 *
 * Usage:
 *   php artisan sales:funnel-report
 *   php artisan sales:funnel-report --days=90
 *   php artisan sales:funnel-report --days=30 --json
 */
class SalesFunnelReportCommand extends Command
{
    protected $signature = 'sales:funnel-report
                            {--days=30 : Window length in days}
                            {--json : Machine-readable JSON on stdout}';

    protected $description = 'Sales funnel: course_page_view → checkout → paid (canonical Payment) → first cabinet action (H2378)';

    public function handle(SalesFunnelReportService $service): int
    {
        $days = max(1, (int) $this->option('days'));
        $report = $service->report(days: $days);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Sales funnel report (as of {$report['as_of']}, last {$report['days']}d)");
        $this->line("Window: {$report['from']} → {$report['to']}");
        $this->newLine();

        $rows = [];
        foreach ($report['steps'] as $name => $step) {
            $rows[] = [
                $name,
                $step['events'],
                $step['unique_users'],
                $step['source'],
            ];
        }
        $this->table(['Step', 'Events', 'Unique users', 'Source'], $rows);

        $this->newLine();
        $pd = $report['paid_denominator'];
        $this->info('Canonical Payment denominator (OrderPaymentConversionService Rate A)');
        $this->line($pd['definition']);
        $this->table(
            ['Orders (denom)', 'Paid', 'Pending', 'Lost', 'Order→paid %'],
            [[
                $pd['orders'],
                $pd['paid'],
                $pd['pending'],
                $pd['lost'],
                $pd['conversion_pct'] === null ? '—' : $pd['conversion_pct'].'%',
            ]],
        );

        $this->newLine();
        $this->info('Rates (null when denominator is 0)');
        foreach ($report['rates'] as $k => $v) {
            $this->line(sprintf('  %s = %s', $k, $v === null ? '—' : $v.'%'));
        }

        $this->newLine();
        $this->info('Denominators (explicit)');
        foreach ($report['denominators_print'] as $line) {
            $this->line('  • '.$line);
        }

        $m = $report['metrika'];
        $this->newLine();
        $this->info(sprintf(
            'Metrika shop counter: %s (enabled=%s)',
            $m['counter_id'] ?? '—',
            $m['enabled'] ? 'yes' : 'no',
        ));
        $this->line('  JS goals: course_page_view · begin_checkout · payment_success');
        $this->line('  Owner repair (if OAuth): python ya_analytics.py create-goals  # ORS-FAQ, adds action goals');
        $this->line('  Privacy: '.$report['privacy']);

        return self::SUCCESS;
    }
}
