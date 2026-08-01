<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
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

    public function test_study_groups_with_chat_filters_by_course_and_active_pivot(): void
    {
        $user = User::factory()->create(['telegram_id' => '9001']);
        $course = Course::factory()->create(['is_active' => true]);
        $other = Course::factory()->create(['is_active' => true]);

        $inCourse = Group::create([
            'name' => 'Чат курса',
            'telegram_chat_id' => '-100111',
            'status' => 'active',
        ]);
        $otherCourse = Group::create([
            'name' => 'Другой курс',
            'telegram_chat_id' => '-100222',
            'status' => 'active',
        ]);
        $left = Group::create([
            'name' => 'Вышел',
            'telegram_chat_id' => '-100333',
            'status' => 'active',
        ]);
        $noChat = Group::create([
            'name' => 'Без чата',
            'telegram_chat_id' => null,
            'status' => 'active',
        ]);

        $course->groups()->attach($inCourse->id);
        $other->groups()->attach($otherCourse->id);
        $course->groups()->attach($left->id);
        $course->groups()->attach($noChat->id);

        $user->groups()->attach($inCourse->id);
        $user->groups()->attach($otherCourse->id);
        $user->groups()->attach($left->id, ['left_at' => now(), 'left_reason' => 'status']);
        $user->groups()->attach($noChat->id);

        $service = app(ZapisiChatMemberService::class);

        $forCourse = $service->studyGroupsWithChat($user, (int) $course->id);
        $this->assertCount(1, $forCourse);
        $this->assertSame('-100111', $forCourse->first()->telegram_chat_id);

        $all = $service->studyGroupsWithChat($user, null);
        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing(
            ['-100111', '-100222'],
            $all->pluck('telegram_chat_id')->all(),
        );
    }

    public function test_kick_from_study_chats_bans_each_chat(): void
    {
        $user = User::factory()->create(['telegram_id' => '777001']);
        $course = Course::factory()->create(['is_active' => true]);
        $g1 = Group::create(['name' => 'G1', 'telegram_chat_id' => '-100A', 'status' => 'active']);
        $g2 = Group::create(['name' => 'G2', 'telegram_chat_id' => '-100B', 'status' => 'active']);
        $course->groups()->attach([$g1->id, $g2->id]);
        $user->groups()->attach([$g1->id, $g2->id]);

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

        $result = app(ZapisiChatMemberService::class)->kickFromStudyChats(
            $user,
            (int) $course->id,
            byUserId: 1,
        );

        $this->assertNull($result['skipped']);
        $this->assertCount(2, $result['ok']);
        $this->assertSame([], $result['failed']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['user_id'] ?? '') === '777001'
            && (string) ($r->data()['chat_id'] ?? '') === '-100A');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'banChatMember')
            && (string) ($r->data()['chat_id'] ?? '') === '-100B');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'unbanChatMember'));
    }

    public function test_kick_from_study_chats_skips_without_telegram_id(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $result = app(ZapisiChatMemberService::class)->kickFromStudyChats($user, 1);
        $this->assertNotNull($result['skipped']);
        $this->assertStringContainsString('telegram_id', $result['skipped']);
        $this->assertSame([], $result['ok']);
    }
}
