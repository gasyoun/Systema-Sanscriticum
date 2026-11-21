<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/** GET /api/n8n/vk-token — n8n забирает действующий VK-токен у прода. */
class VkTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'vk-token-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 12:00:00');
        config(['services.n8n.vk_token_secret' => self::SECRET]);
        $this->mock(TelegramAdminNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyAdmins')->zeroOrMoreTimes());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(array $overrides = []): VkUserToken
    {
        return VkUserToken::create(array_merge([
            'client_id' => '54821902',
            'access_token' => 'vk-access',
            'refresh_token' => 'vk-refresh',
            'scope' => 'wall video offline',
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function fetchToken(?string $secret = self::SECRET): TestResponse
    {
        return $this->getJson('/api/n8n/vk-token', $secret === null ? [] : ['X-Webhook-Secret' => $secret]);
    }

    public function test_empty_secret_disables_endpoint(): void
    {
        config(['services.n8n.vk_token_secret' => '']);
        $this->token();

        $this->fetchToken('')->assertForbidden();
    }

    public function test_wrong_or_missing_secret_is_rejected(): void
    {
        $this->token();

        $this->fetchToken('nope')->assertForbidden();
        $this->fetchToken(null)->assertForbidden();
    }

    public function test_fresh_token_returned_without_refresh_and_never_leaks_refresh_token(): void
    {
        Http::fake();
        $this->token();

        $res = $this->fetchToken()->assertOk()
            ->assertJson(['access_token' => 'vk-access', 'has_video' => true, 'scope' => 'wall video offline']);

        $this->assertStringNotContainsString('vk-refresh', (string) $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_token_close_to_expiry_is_refreshed_first(): void
    {
        Http::fake(['id.vk.com/*' => Http::response([
            'access_token' => 'vk-access-new', 'refresh_token' => 'vk-refresh-new',
            'expires_in' => 3600, 'scope' => 'wall video offline',
        ])]);
        $this->token(['expires_at' => now()->addMinutes(10)]);

        $this->fetchToken()->assertOk()->assertJson(['access_token' => 'vk-access-new']);
        $this->assertSame('vk-refresh-new', VkUserToken::current()->refresh_token);
    }

    public function test_expired_token_with_failed_refresh_gives_503(): void
    {
        Http::fake(['id.vk.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->token(['expires_at' => now()->subMinute()]);

        $this->fetchToken()->assertStatus(503)->assertJson(['error' => 'no_active_token']);
    }

    public function test_no_token_row_gives_503(): void
    {
        $this->fetchToken()->assertStatus(503);
    }
}
