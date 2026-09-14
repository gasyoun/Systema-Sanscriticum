<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OpenrouterBalanceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MG 24-08-2026 (Play A-2): balance + burn-rate forecast + yearly top-up ask.
 */
class OpenrouterBalanceCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'openrouter.key' => 'test-key',
            'openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'openrouter.telegram_chat_id' => '11111',
            'openrouter.alert_within_days' => 14,
            'openrouter.min_baseline_days' => 7,
            'openrouter.lookback_days' => 28,
            'openrouter.enabled' => true,
        ]);
        Cache::flush();
    }

    /**
     * Http::fake() merges stubs and the FIRST match wins, so every test
     * registers its own complete set exactly once.
     */
    private function fakeUpstream(float $credits, float $usage): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://openrouter.ai/*' => Http::response(['data' => ['total_credits' => $credits, 'total_usage' => $usage]], 200),
        ]);
    }

    public function test_empty_key_is_skip_soft(): void
    {
        config(['openrouter.key' => '']);

        $this->assertSame(1, Artisan::call('openrouter:balance-check'));
        $this->assertStringContainsString('skip-soft', Artisan::output());
    }

    public function test_dry_reports_without_persisting_snapshot(): void
    {
        $this->fakeUpstream(100.0, 40.0);
        $code = Artisan::call('openrouter:balance-check', ['--dry' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('$60.00', $out);
        $this->assertSame(0, OpenrouterBalanceSnapshot::count());
    }

    public function test_live_run_stores_snapshot(): void
    {
        $this->fakeUpstream(100.0, 40.0);
        Artisan::call('openrouter:balance-check');

        $this->assertSame(1, OpenrouterBalanceSnapshot::count());
        $row = OpenrouterBalanceSnapshot::first();
        $this->assertEquals('100.00', $row->total_credits);
        $this->assertEquals('40.00', $row->total_usage);
    }

    public function test_forecast_below_baseline_days_stays_quiet(): void
    {
        $this->fakeUpstream(100.0, 48.0);
        // 5 days of history — below min_baseline_days=7: no forecast, no alert.
        foreach (range(0, 4) as $i) {
            OpenrouterBalanceSnapshot::create([
                'snapshot_date' => CarbonImmutable::now()->subDays(5 - $i),
                'total_credits' => 100.0,
                'total_usage' => 40.0 + $i * 2,
            ]);
        }

        $this->assertSame(0, Artisan::call('openrouter:balance-check', ['--dry' => true]));
        $this->assertStringContainsString('базовая линия', Artisan::output());
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_threshold_crossed_sends_topup_request_with_year_size(): void
    {
        $this->fakeUpstream(50.0, 44.0);

        // usage grows 3/day for 10 days → daily_avg=3; remaining 60 → 20 days left? No:
        // make remaining small so days_left <=14. credits=50, last usage=44 → rem 6.
        foreach (range(0, 9) as $i) {
            OpenrouterBalanceSnapshot::create([
                'snapshot_date' => CarbonImmutable::now()->subDays(10 - $i),
                'total_credits' => 50.0,
                'total_usage' => 14.0 + $i * 3,
            ]);
        }
        $code = Artisan::call('openrouter:balance-check');
        $out = Artisan::output();

        $this->assertSame(1, $code, "code=$code out=[$out]");
        $this->assertStringContainsString('попросить пополнение', $out);
        // daily 3.00 × 365 × 1.25 = 1368.75 → round up to 10 = 1370
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '$1,370'));
    }

    public function test_alert_dedupes_within_day(): void
    {
        $this->fakeUpstream(50.0, 44.0);

        foreach (range(0, 9) as $i) {
            OpenrouterBalanceSnapshot::create([
                'snapshot_date' => CarbonImmutable::now()->subDays(10 - $i),
                'total_credits' => 50.0,
                'total_usage' => 14.0 + $i * 3,
            ]);
        }
        Artisan::call('openrouter:balance-check');
        Artisan::call('openrouter:balance-check');

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->count();
        $this->assertSame(1, $sent);
        $this->assertTrue(Cache::has('openrouter_balance_alert:'.now()->toDateString()));
    }

    public function test_kernel_schedules_daily_check_with_kill_switch(): void
    {
        $schedule = $this->app->make(ConsoleSchedule::class);
        /** @var ?Event $event */
        $event = null;
        foreach ($schedule->events() as $candidate) {
            if (str_contains((string) $candidate->command, 'openrouter:balance-check')) {
                $event = $candidate;
                break;
            }
        }

        $this->assertNotNull($event);
        $this->assertSame('20 9 * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));
        config(['openrouter.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
