<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrencyRateProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyRateProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.exchangerate.key', 'test-key');
    }

    private function fakeQuotes(): void
    {
        Http::fake([
            'api.exchangerate.host/*' => Http::response([
                'success' => true,
                'source' => 'USD',
                'quotes' => ['USDRUB' => 90.0, 'USDEUR' => 0.9],
            ]),
        ]);
    }

    /** @test */
    public function usd_rate_is_rubles_per_usd(): void
    {
        $this->fakeQuotes();

        $rate = app(CurrencyRateProvider::class)->rublesPerUnit('USD', Carbon::parse('2026-05-10'));

        $this->assertEqualsWithDelta(90.0, $rate, 0.0001);
    }

    /** @test */
    public function eur_rate_is_cross_rate_usdrub_over_usdeur(): void
    {
        $this->fakeQuotes();

        // 90 / 0.9 = 100
        $rate = app(CurrencyRateProvider::class)->rublesPerUnit('EUR', Carbon::parse('2026-05-10'));

        $this->assertEqualsWithDelta(100.0, $rate, 0.0001);
    }

    /** @test */
    public function returns_null_when_api_reports_failure(): void
    {
        Http::fake([
            'api.exchangerate.host/*' => Http::response(['success' => false, 'error' => ['code' => 302]]),
        ]);

        $rate = app(CurrencyRateProvider::class)->rublesPerUnit('USD', Carbon::parse('2026-05-11'));

        $this->assertNull($rate);
    }

    /** @test */
    public function returns_null_and_does_not_call_api_when_key_missing(): void
    {
        Config::set('services.exchangerate.key', null);
        Http::fake();

        $rate = app(CurrencyRateProvider::class)->rublesPerUnit('USD', Carbon::parse('2026-05-12'));

        $this->assertNull($rate);
        Http::assertNothingSent();
    }

    /** @test */
    public function returns_null_for_unsupported_currency(): void
    {
        Http::fake();

        $rate = app(CurrencyRateProvider::class)->rublesPerUnit('GBP', Carbon::parse('2026-05-13'));

        $this->assertNull($rate);
        Http::assertNothingSent();
    }
}
