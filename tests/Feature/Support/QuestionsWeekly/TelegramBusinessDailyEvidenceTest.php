<?php

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportScanDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5709 — суточное доказательство живости вебхук-полосы Telegram Business
 * (telegram-business:daily-evidence): только успешный сетевой проб
 * getWebhookInfo записывает день охвата; любой провал оставляет день
 * неизвестным (H5768) и кладёт ошибку на строку аккаунта (sync_error).
 */
class TelegramBusinessDailyEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        // 05-10-2026 08:00 MSK — внутри недели, день доказательства = 05-10.
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Europe/Moscow'));

        config([
            'features.telegram_business_bot' => true,
            'services.telegram_business.token' => 'test-token',
            'services.telegram_business.account_name' => 'telegram-business',
        ]);

        $this->account = TelegramSupportAccount::create([
            'name' => 'telegram-business',
            'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeHealthyWebhook(): void
    {
        Http::fake([
            'api.telegram.org/bottest-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://ors.example/telegram-business/webhook'],
            ]),
        ]);
    }

    public function test_successful_probe_records_today_scan_day_and_freshens_account(): void
    {
        $this->account->forceFill(['last_sync_error' => 'старая ошибка'])->save();

        $this->fakeHealthyWebhook();

        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();

        $day = TelegramSupportScanDay::query()
            ->where('account_name', 'telegram-business')
            ->where('day', '2026-10-05')
            ->first();

        $this->assertNotNull($day, 'день охвата должен быть записан');
        $this->assertSame(1, $day->successful_runs);
        $this->assertSame(0, $day->peers_polled_max, 'вебхук-полоса пиров не опрашивает');

        $this->account->refresh();
        $this->assertNull($this->account->last_sync_error);
        $this->assertNotNull($this->account->last_successful_sync_at);
    }

    public function test_repeat_run_same_day_increments_counter_without_duplicate_rows(): void
    {
        $this->fakeHealthyWebhook();

        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();
        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();

        $this->assertSame(
            1,
            TelegramSupportScanDay::query()
                ->where('account_name', 'telegram-business')
                ->where('day', '2026-10-05')
                ->count(),
        );

        $this->assertSame(
            2,
            TelegramSupportScanDay::query()
                ->where('account_name', 'telegram-business')
                ->where('day', '2026-10-05')
                ->value('successful_runs'),
        );
    }

    public function test_failed_http_probe_records_error_and_writes_no_scan_day(): void
    {
        Http::fake([
            'api.telegram.org/bottest-token/getWebhookInfo' => Http::response(['ok' => false], 500),
        ]);

        $this->artisan('telegram-business:daily-evidence')->assertFailed();

        $this->assertSame(
            0,
            TelegramSupportScanDay::query()->where('account_name', 'telegram-business')->count(),
            'проваленный проб день не доказывает',
        );

        $this->account->refresh();
        $this->assertStringContainsString('daily-evidence', (string) $this->account->last_sync_error);
        $this->assertStringContainsString('http 500', (string) $this->account->last_sync_error);
    }

    public function test_recent_webhook_delivery_error_fails_probe(): void
    {
        Http::fake([
            'api.telegram.org/bottest-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://ors.example/telegram-business/webhook',
                    'last_error_date' => time() - 3600,
                    'last_error_message' => 'Bad Gateway',
                ],
            ]),
        ]);

        $this->artisan('telegram-business:daily-evidence')->assertFailed();

        $this->assertSame(
            0,
            TelegramSupportScanDay::query()->where('account_name', 'telegram-business')->count(),
        );
    }

    public function test_stale_delivery_error_does_not_block_evidence(): void
    {
        Http::fake([
            'api.telegram.org/bottest-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://ors.example/telegram-business/webhook',
                    'last_error_date' => time() - 7 * 86400,
                ],
            ]),
        ]);

        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();

        $this->assertSame(
            1,
            TelegramSupportScanDay::query()->where('account_name', 'telegram-business')->count(),
        );
    }

    public function test_empty_webhook_url_fails_probe(): void
    {
        Http::fake([
            'api.telegram.org/bottest-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => ''],
            ]),
        ]);

        $this->artisan('telegram-business:daily-evidence')->assertFailed();

        $this->assertSame(
            0,
            TelegramSupportScanDay::query()->where('account_name', 'telegram-business')->count(),
        );
    }

    public function test_feature_off_is_noop_without_network_call(): void
    {
        config(['features.telegram_business_bot' => false]);
        Http::fake();

        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, TelegramSupportScanDay::query()->count());

        $this->account->refresh();
        $this->assertNull($this->account->last_synced_at);
        $this->assertNull($this->account->last_sync_error);
    }

    public function test_missing_token_fails_probe_without_network_call(): void
    {
        config(['services.telegram_business.token' => '']);
        Http::fake();

        $this->artisan('telegram-business:daily-evidence')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, TelegramSupportScanDay::query()->count());
    }

    public function test_missing_account_row_is_ignored(): void
    {
        $this->account->delete();
        $this->fakeHealthyWebhook();

        $this->artisan('telegram-business:daily-evidence')->assertSuccessful();

        // Строки аккаунта нет → он не в source_scope coverage и доказательств
        // под его имя не пишется.
        $this->assertSame(0, TelegramSupportScanDay::query()->count());
    }
}
