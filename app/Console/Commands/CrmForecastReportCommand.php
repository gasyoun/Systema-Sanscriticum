<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\SalesForecastService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * CRM Wave 3 (H2485) — read-only forecast / backtest dump.
 *
 * Works while the UI flag is OFF. Never writes Payment rows.
 *
 *   php artisan crm:forecast-report
 *   php artisan crm:forecast-report --json
 *   php artisan crm:forecast-report --manager=12
 */
final class CrmForecastReportCommand extends Command
{
    protected $signature = 'crm:forecast-report
                            {--manager= : Restrict pipeline to deals.assigned_to and actuals to payments.created_by_user_id}
                            {--as-of= : ISO datetime (default: now)}
                            {--json : Machine-readable JSON}';

    protected $description = 'CRM Wave 3: read-only pipeline forecast and backtest (never writes payments)';

    public function handle(SalesForecastService $forecast): int
    {
        $asOfOpt = $this->option('as-of');
        $asOf = is_string($asOfOpt) && $asOfOpt !== '' ? Carbon::parse($asOfOpt) : now();
        $managerOpt = $this->option('manager');
        $managerId = is_numeric($managerOpt) ? (int) $managerOpt : null;

        $snap = $forecast->snapshot($asOf, $managerId);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $pipe = $snap['pipeline'];
        $this->info('As of '.$snap['as_of'].' · horizon '.$snap['horizon_days'].'d');
        $this->line('Open pipeline: '.$pipe['deal_count'].' deals / '.$pipe['open_amount'].' ₽');
        $this->line('Weighted forecast: '.$pipe['weighted_low'].' … '.$pipe['weighted_mid'].' … '.$pipe['weighted_high'].' ₽');
        $this->line('Previous-horizon actuals: '.$snap['actuals_previous_horizon']['paid_amount'].' ₽ ('.$snap['actuals_previous_horizon']['paid_count'].' paid)');
        $this->newLine();
        $this->line('Backtest:');
        foreach ($snap['backtest'] as $row) {
            $this->line(sprintf(
                '  %dd %s  forecast=%s  actual=%s  error=%s',
                $row['window_days'],
                $row['status'],
                $row['forecast_mid'] === null ? '—' : (string) $row['forecast_mid'],
                $row['actual_amount'] === null ? '—' : (string) $row['actual_amount'],
                $row['error'] === null ? '—' : (string) $row['error'],
            ));
            if (is_string($row['reason'] ?? null) && $row['reason'] !== '') {
                $this->line('    '.$row['reason']);
            }
        }

        return self::SUCCESS;
    }
}
