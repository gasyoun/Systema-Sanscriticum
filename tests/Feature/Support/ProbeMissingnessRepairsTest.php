<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\MoneySli\MoneySliAlerter;
use App\Support\MoneySli\MoneySliAlertState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * H5061 — per-defect regression tests for the four repaired probe/SLI
 * seams. Each test pins one repaired violation:
 *
 *  1. MoneySliAlerter::heartbeat — empty ping URL was a SILENT return
 *     (missing config exits quietly); must now warn loudly + machine-readable.
 *  2. money:sli-synthetic-pay — feature OFF exited green with NO metric
 *     record; must now append a not_supported TSV row.
 *  3. money:sli-hourly-reconcile — feature OFF exited green quietly;
 *     must now warn loudly.
 *  4. heartbeat:ping — missing HEARTBEAT_PING_URL was console-only;
 *     must now warn into the log channel too.
 *  5. telegram-support:healthcheck — zero enabled accounts was a quiet
 *     green; must now warn (not_supported), not «info».
 */
final class ProbeMissingnessRepairsTest extends TestCase
{
    use RefreshDatabase;

    private string $tsvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsvPath = storage_path('app/money_sli/test_'.uniqid('h5061_', true).'.tsv');
        config()->set('money_sli.tsv_path', $this->tsvPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->tsvPath);
        parent::tearDown();
    }

    public function test_money_sli_heartbeat_with_empty_url_is_loud_and_sends_nothing(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $ctx): bool => str_contains($message, 'heartbeat URL пуст') && $ctx['state'] === 'not_supported');
        Http::fake();

        (new MoneySliAlerter(new MoneySliAlertState))->heartbeat('', healthy: true, failSummary: 'unused', dry: false);

        Http::assertNothingSent();
    }

    public function test_money_sli_heartbeat_with_url_still_sends(): void
    {
        Http::fake();

        (new MoneySliAlerter(new MoneySliAlertState))->heartbeat('https://uptime.example.invalid/ping/x', healthy: true, failSummary: '', dry: false);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://uptime.example.invalid/ping/x');
    }

    public function test_synthetic_pay_feature_off_appends_not_supported_tsv_row(): void
    {
        config()->set('features.money_sli_synthetic_pay', false);
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'synthetic-pay не вооружён'));

        $this->artisan('money:sli-synthetic-pay')->assertSuccessful();

        $rows = $this->tsvRows();
        self::assertNotFalse($rows, 'TSV must be written even when the seam is unarmed');
        self::assertCount(1, $rows, 'exactly one not_supported data row (daily cadence, no spam; header excluded)');
        self::assertSame('synthetic_pay', $rows[0]['check']);
        self::assertSame('not_supported', $rows[0]['status']);
    }

    public function test_hourly_reconcile_feature_off_is_loud(): void
    {
        config()->set('features.money_sli_hourly_reconcile', false);
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'hourly-reconcile не вооружён'));

        $this->artisan('money:sli-hourly-reconcile')->assertSuccessful();

        self::assertFalse(is_file($this->tsvPath), 'hourly cadence must NOT spam the daily TSV with not_supported rows');
    }

    public function test_scheduler_heartbeat_with_missing_url_is_loud(): void
    {
        config()->set('heartbeat.url', '');
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m, array $ctx): bool => str_contains($m, 'НЕ вооружён') && $ctx['state'] === 'not_supported');
        Http::fake();

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_telegram_support_healthcheck_with_zero_enabled_accounts_is_not_quiet_green(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m, array $ctx): bool => str_contains($m, 'включённых аккаунтов 0') && $ctx['state'] === 'not_supported');

        $this->artisan('telegram-support:healthcheck')
            ->expectsOutputToContain('not_supported')
            ->assertSuccessful();
    }

    /**
     * @return list<array<string, string>>|false
     */
    private function tsvRows(): array|false
    {
        if (! is_file($this->tsvPath)) {
            return false;
        }
        $lines = array_values(array_filter(explode("\n", rtrim((string) file_get_contents($this->tsvPath), "\n")), fn ($l) => $l !== ''));
        if ($lines === []) {
            return false;
        }
        $header = explode("\t", $lines[0]);
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $rows[] = array_combine($header, explode("\t", $line));
        }

        return $rows;
    }
}
