<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\PaypalForeignPriceService;
use Illuminate\Console\Command;

/**
 * H3821 — monthly refresh of the published fixed EUR/USD price list. Reads
 * only active tariffs + the fx rate provider; writes only
 * tariff_foreign_prices (never payments/payment_audits). --dry-run prints
 * the same report without writing — the human-review artifact required
 * before flipping features.paypal_fixed_price_list.
 */
class RefreshPaypalForeignPrices extends Command
{
    protected $signature = 'paypal:refresh-foreign-prices {--dry-run : Compute and print without writing to tariff_foreign_prices}';

    protected $description = 'Recompute the published fixed EUR/USD PayPal price per active tariff (H3821)';

    public function handle(PaypalForeignPriceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = $service->refreshAll($dryRun);

        $this->table(
            ['Tariff ID', 'Course', 'Title', 'RUB', 'Currency', 'FX rate (₽)', 'Price', 'Status'],
            array_map(fn (array $r) => [
                $r['tariff_id'],
                $r['course_id'],
                $r['title'],
                number_format($r['rub_price'], 2, '.', ' '),
                $r['currency'],
                $r['fx_rate'] !== null ? number_format($r['fx_rate'], 4, '.', ' ') : '—',
                $r['price'] !== null ? number_format($r['price'], 2, '.', ' ') : '—',
                $r['skipped'] ? 'SKIPPED (no fx rate)' : ($dryRun ? 'dry-run' : 'written'),
            ], $rows),
        );

        $skipped = count(array_filter($rows, fn (array $r) => $r['skipped']));
        if ($skipped > 0) {
            $this->warn("{$skipped} row(s) skipped — no fx rate available (EXCHANGERATE_HOST_KEY missing or API call failed).");
        }

        if ($dryRun) {
            $this->info('Dry run — tariff_foreign_prices was NOT written.');
        } else {
            $this->info('tariff_foreign_prices refreshed: '.(count($rows) - $skipped).' row(s) written.');
        }

        return self::SUCCESS;
    }
}
