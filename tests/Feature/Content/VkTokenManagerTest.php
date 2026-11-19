<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\VkTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class VkTokenManagerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $notified = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notifyAdmins')->andReturnUsing(function (string $text): array {
                $this->notified[] = $text;

                return [];
            });
        });
    }

    private function fakeOauth(int $status = 200, array $body = []): void
    {
        Http::fake(['id.vk.com/*' => Http::response($body ?: [
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
            'user_id' => 14488224,
            'scope' => 'wall offline vkid.personal_info',
        ], $status)]);
    }

    public function test_import_takes_ownership_by_refreshing_and_encrypts_secrets(): void
    {
        $this->fakeOauth();

        $token = app(VkTokenManager::class)->import([
            'VK_APP_ID' => '54821902',
            'VK_ACCESS_TOKEN' => 'old-access',
            'VK_REFRESH_TOKEN' => 'old-refresh',
        ]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://id.vk.com/oauth2/auth'
            && $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === 'old-refresh'
            && $r['client_id'] === '54821902');
        $this->assertSame('new-access', $token->access_token);
        $this->assertSame('new-refresh', $token->refresh_token, 'ротированный refresh_token сохранён');
        $this->assertTrue($token->hasScope('wall'));
        $this->assertFalse($token->hasScope('photos'));
        $this->assertSame(14488224, $token->vk_user_id);
        $this->assertSame('2026-10-22 13:00:00', $token->expires_at->toDateTimeString());

        $raw = DB::table('vk_user_tokens')->first();
        $this->assertStringNotContainsString('new-access', $raw->access_token);
        $this->assertStringNotContainsString('new-refresh', $raw->refresh_token);
    }

    public function test_refresh_skipped_while_token_is_fresh(): void
    {
        $this->fakeOauth();
        VkUserToken::create(['client_id' => '1', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(50)]);

        $this->artisan('vk:token-refresh')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_refresh_rotates_pair_when_close_to_expiry(): void
    {
        $this->fakeOauth();
        VkUserToken::create(['client_id' => '1', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(20)]);

        $this->artisan('vk:token-refresh')->assertSuccessful();

        $token = VkUserToken::current();
        $this->assertSame('new-access', $token->access_token);
        $this->assertSame('new-refresh', $token->refresh_token);
        $this->assertSame(VkUserToken::activeAccessToken(), 'new-access');
    }

    public function test_failed_refresh_notifies_once_and_keeps_old_pair(): void
    {
        $this->fakeOauth(400, ['error' => 'invalid_grant', 'error_description' => 'refresh token is expired']);
        VkUserToken::create(['client_id' => '1', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(20)]);

        $this->artisan('vk:token-refresh')->assertSuccessful();
        $this->artisan('vk:token-refresh')->assertSuccessful();

        $token = VkUserToken::current();
        $this->assertSame('r', $token->refresh_token);
        $this->assertStringContainsString('refresh token is expired', $token->last_error);
        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('~20 мин', $this->notified[0]);

        Carbon::setTestNow(now()->addMinutes(25));
        $this->artisan('vk:token-refresh')->assertFailed();
        $this->assertNull(VkUserToken::activeAccessToken());
    }

    public function test_revoked_refresh_key_asks_for_reauthorization_at_once(): void
    {
        $this->fakeOauth(400, ['error' => 'invalid_grant', 'error_description' => 'refresh_token is missing or invalid']);
        VkUserToken::create(['client_id' => '1', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(30)]);

        $this->artisan('vk:token-refresh')->assertSuccessful();

        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('сам не восстановится', $this->notified[0]);
    }

    public function test_lost_connection_is_reported_not_crashed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        VkUserToken::create(['client_id' => '1', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(30)]);

        $this->artisan('vk:token-refresh')->assertSuccessful();

        $this->assertStringContainsString('id.vk.com не ответил', VkUserToken::current()->last_error);
        $this->assertCount(1, $this->notified);
    }

    public function test_refresh_is_noop_without_imported_token(): void
    {
        Http::fake();

        $this->artisan('vk:token-refresh')->assertSuccessful();

        Http::assertNothingSent();
    }
}
