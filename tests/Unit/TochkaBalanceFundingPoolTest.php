<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\TochkaBalanceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5554 gap [2] — the payout funding pool is the Tochka operating account
 * (…863757) only: accounts whose tail is listed in
 * payroll_readiness.funding_excluded_account_tails (tax wallet …877617) are
 * excluded, and closing_total stays untouched for other consumers.
 */
final class TochkaBalanceFundingPoolTest extends TestCase
{
    private function fakeTwoAccounts(): void
    {
        config()->set('services.tochka.token', 'fixture-token');
        Http::fake(['*' => Http::response([
            'Data' => ['Balance' => [
                [
                    'accountId' => '40702810000000123456/RUB',
                    'type' => 'ClosingAvailable',
                    'Amount' => ['amount' => 1000, 'currency' => 'RUB'],
                    'dateTime' => '2026-10-01T08:00:00+03:00',
                ],
                [
                    'accountId' => '408028103000000877617/RUB',
                    'type' => 'ClosingAvailable',
                    'Amount' => ['amount' => 100000, 'currency' => 'RUB'],
                    'dateTime' => '2026-10-01T08:00:00+03:00',
                ],
            ]],
        ])]);
        Cache::forget(TochkaBalanceService::CACHE_KEY);
    }

    public function test_funding_pool_sums_only_non_excluded_accounts(): void
    {
        $this->fakeTwoAccounts();
        config()->set('payroll_readiness.funding_excluded_account_tails', ['877617']);

        $this->assertSame(1000.0, app(TochkaBalanceService::class)->fundingPool());
    }

    public function test_funding_pool_uses_full_closing_total_without_exclusions(): void
    {
        $this->fakeTwoAccounts();
        config()->set('payroll_readiness.funding_excluded_account_tails', []);

        $this->assertSame(101000.0, app(TochkaBalanceService::class)->fundingPool());
    }

    public function test_funding_pool_is_null_when_snapshot_unavailable(): void
    {
        config()->set('services.tochka.token', '');
        Cache::forget(TochkaBalanceService::CACHE_KEY);

        $this->assertNull(app(TochkaBalanceService::class)->fundingPool());
    }
}
