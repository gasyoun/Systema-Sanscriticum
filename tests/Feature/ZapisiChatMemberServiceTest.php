<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use App\Services\Telegram\ZapisiChatMemberException;
use App\Services\Telegram\ZapisiChatMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZapisiChatMemberServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
    }

    private function fakeZapisi(callable $handler): void
    {
        Http::fake(function ($request) use ($handler) {
            return $handler($request);
        });
    }

    public function test_bot_rights_ok_when_admin_can_restrict(): void
    {
        $this->fakeZapisi(function ($request) {
            if (str_contains($request->url(), 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99, 'username' => 'zapisi_ORSbot']]);
            }
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'administrator', 'can_restrict_members' => true],
                ]);
            }

            return Http::response(['ok' => false], 400);
        });

        $rights = app(ZapisiChatMemberService::class)->botRightsInChat('-100111');
        $this->assertTrue($rights['ok']);
        $this->assertTrue($rights['can_restrict']);
    }

    public function test_bot_rights_fail_when_only_member(): void
    {
        $this->fakeZapisi(function ($request) {
            if (str_contains($request->url(), 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'member', 'can_restrict_members' => false],
                ]);
            }

            return Http::response(['ok' => false], 400);
        });

        $rights = app(ZapisiChatMemberService::class)->botRightsInChat('-100111');
        $this->assertFalse($rights['ok']);
        $this->assertStringContainsString('админ', $rights['detail']);
    }

    public function test_kick_is_hard_ban_without_unban(): void
    {
        $this->fakeZapisi(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($url, 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'administrator', 'can_restrict_members' => true],
                ]);
            }
            if (str_contains($url, 'banChatMember')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => false], 400);
        });

        app(ZapisiChatMemberService::class)->kick('-100555', 777001, byUserId: 1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['chat_id'] ?? '') === '-100555'
            && (string) ($r->data()['user_id'] ?? '') === '777001');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'unbanChatMember'));
    }

    public function test_unban_calls_unban_chat_member(): void
    {
        $this->fakeZapisi(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($url, 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'administrator', 'can_restrict_members' => true],
                ]);
            }
            if (str_contains($url, 'unbanChatMember')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => false], 400);
        });

        app(ZapisiChatMemberService::class)->unban('-100555', 777001, byUserId: 1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'unbanChatMember')
            && (string) ($r->data()['chat_id'] ?? '') === '-100555'
            && (string) ($r->data()['user_id'] ?? '') === '777001'
            && ($r->data()['only_if_banned'] ?? false) === true);
    }

    public function test_kick_throws_when_bot_cannot_restrict(): void
    {
        $this->fakeZapisi(function ($request) {
            if (str_contains($request->url(), 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['status' => 'member'],
                ]);
            }

            return Http::response(['ok' => false], 400);
        });

        $this->expectException(ZapisiChatMemberException::class);
        app(ZapisiChatMemberService::class)->kick('-1001', 2);
    }
}
