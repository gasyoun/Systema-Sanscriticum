<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatSilenceRemoval;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\Discipline\ChatSilenceRemovalEligibility;
use App\Services\Telegram\ChatJoinRequestApprover;
use App\Services\Telegram\ZapisiBanJournal;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * «Молчит в учебном чате N дней И кабинет закрыл урок» → предложение админам →
 * бан Почтальоном только по нажатию admin/super_admin.
 */
class ChatSilenceRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '-100777';

    private const STUDENT_TG = '555';

    private const ADMIN_TG = '9001';

    private string $store;

    private Course $course;

    private Group $group;

    private User $student;

    private User $admin;

    private bool $banOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $this->store = storage_path('framework/testing/chat-silence-'.uniqid());
        File::ensureDirectoryExists($this->store.'/roster');

        config([
            'services.telegram_harvest.store_path' => $this->store,
            'services.telegram_harvest.roster_stale_after_hours' => 6,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => self::ADMIN_TG,
            'services.telegram.bot_webhook_secret' => 'test-tg',
            'features.paid_chat_join_request' => true,
            'chat_silence_removal.enabled' => true,
            'chat_silence_removal.silence_days' => 7,
            'chat_silence_removal.access_grace_days' => 3,
        ]);

        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 99]]);
            }
            if (str_contains($url, 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_restrict_members' => true]]);
            }
            if (str_contains($url, 'banChatMember')) {
                return $this->banOk
                    ? Http::response(['ok' => true, 'result' => true])
                    : Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $this->course = Course::factory()->create(['title' => 'Грамматика тест']);
        $this->group = Group::create(['name' => 'Грамматика гр.1', 'status' => 'active', 'telegram_chat_id' => self::CHAT]);
        $this->course->groups()->attach($this->group->id);

        Lesson::factory()->create([
            'course_id' => $this->course->id,
            'block_number' => 2,
            'lesson_date' => now()->subDays(5),
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
        ]);

        $this->student = User::factory()->create(['name' => 'Молчун', 'telegram_id' => self::STUDENT_TG]);
        $this->group->users()->attach($this->student->id, ['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);
        $this->pay($this->student, 'block_1');

        $this->admin = User::factory()->create(['role' => Roles::ADMIN, 'telegram_id' => self::ADMIN_TG]);

        $this->roster([self::STUDENT_TG, '666']);
        // Кто-то в чате пишет — значит, вебхук живой и молчание студента реально.
        $this->message('666', now()->subDay());
        $this->message(self::STUDENT_TG, now()->subDays(20));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_silent_student_with_closed_lesson_gets_one_proposal_and_not_twice(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();

        $this->assertSame(1, $this->proposalsSent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains((string) ($r->data()['reply_markup'] ?? ''), 'csr:'.$this->student->id)
            && str_contains((string) ($r->data()['reply_markup'] ?? ''), 'css:'.$this->student->id)
            && str_contains((string) ($r->data()['text'] ?? ''), 'Молчун'));

        $row = ChatSilenceRemoval::sole();
        $this->assertSame(ChatSilenceRemoval::STATUS_PROPOSED, $row->status);
        $this->assertSame(2, $row->block_number);
        $this->assertSame(0, $this->sent('banChatMember'), 'без нажатия админа Telegram не трогаем');
    }

    public function test_disabled_switch_sends_nothing_but_dry_run_reports(): void
    {
        config(['chat_silence_removal.enabled' => false]);

        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->artisan('chats:silence-removal-propose --dry-run')
            ->expectsOutputToContain('user#'.$this->student->id)
            ->assertSuccessful();

        $this->assertSame(0, $this->proposalsSent());
        $this->assertSame(0, ChatSilenceRemoval::count());
    }

    public function test_blockers_keep_student_out(): void
    {
        $this->assertBlocker([]);

        // Писал за неделю.
        $this->message(self::STUDENT_TG, now()->subDays(2));
        $this->assertBlocker([ChatSilenceRemovalEligibility::BLOCKER_WROTE_RECENTLY]);
    }

    public function test_open_lesson_blocks(): void
    {
        $this->pay($this->student, 'block_2');

        $this->assertBlocker([ChatSilenceRemovalEligibility::BLOCKER_LESSON_OPEN]);
    }

    public function test_staff_and_recent_member_and_unlinked_are_not_candidates(): void
    {
        $this->student->forceFill(['role' => Roles::TEACHER])->save();
        $this->assertBlocker([ChatSilenceRemovalEligibility::BLOCKER_STAFF]);

        $this->student->forceFill(['role' => null])->save();
        $this->group->users()->updateExistingPivot($this->student->id, ['created_at' => now()->subDays(2)]);
        $this->assertBlocker([ChatSilenceRemovalEligibility::BLOCKER_RECENTLY_JOINED]);

        $unlinked = app(ChatSilenceRemovalEligibility::class)->candidates()
            ->first(fn ($c) => $c->telegramUserId === '666');
        $this->assertContains(ChatSilenceRemovalEligibility::BLOCKER_UNLINKED, $unlinked->blockers);
        $this->assertFalse($unlinked->isEligible());
    }

    public function test_stale_roster_or_silent_chat_skips_whole_chat(): void
    {
        $this->roster([self::STUDENT_TG], now()->subHours(10));
        $eligibility = app(ChatSilenceRemovalEligibility::class);
        $this->assertCount(0, $eligibility->candidates());
        $this->assertSame(ChatSilenceRemovalEligibility::SKIP_STALE_ROSTER, $eligibility->skippedChats()[self::CHAT]);

        $this->roster([self::STUDENT_TG, '666']);
        File::deleteDirectory($this->store.'/corpus');
        $this->assertCount(0, $eligibility->candidates());
        $this->assertSame(ChatSilenceRemovalEligibility::SKIP_SILENT_CHAT, $eligibility->skippedChats()[self::CHAT]);
    }

    public function test_admin_press_bans_records_and_edits_message(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();

        $this->press('csr:', self::ADMIN_TG);

        $this->assertSame(1, $this->sent('banChatMember'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'botZAPISI-TOKEN/banChatMember')
            && (string) $r['chat_id'] === self::CHAT
            && (string) $r['user_id'] === self::STUDENT_TG);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageText')
            && str_contains((string) $r['text'], 'Исключён из: Грамматика гр.1'));

        $row = ChatSilenceRemoval::sole();
        $this->assertSame(ChatSilenceRemoval::STATUS_REMOVED, $row->status);
        $this->assertSame($this->admin->id, $row->decided_by);

        $journal = app(ZapisiBanJournal::class)->all(self::CHAT);
        $this->assertSame((int) self::STUDENT_TG, $journal[0]['id']);
    }

    public function test_double_press_bans_once(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();

        $this->press('csr:', self::ADMIN_TG);
        $this->press('csr:', self::ADMIN_TG);

        $this->assertSame(1, $this->sent('banChatMember'));
    }

    public function test_manager_cannot_ban(): void
    {
        User::factory()->create(['role' => Roles::MANAGER, 'telegram_id' => '9002']);
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();

        $this->press('csr:', '9002');

        $this->assertSame(0, $this->sent('banChatMember'));
        $this->assertSame(ChatSilenceRemoval::STATUS_PROPOSED, ChatSilenceRemoval::sole()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery')
            && ($r['text'] ?? '') === 'Недостаточно прав.');
    }

    public function test_paid_between_proposal_and_press_is_not_banned(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->pay($this->student, 'block_2');

        $this->press('csr:', self::ADMIN_TG);

        $this->assertSame(0, $this->sent('banChatMember'));
        $row = ChatSilenceRemoval::sole();
        $this->assertSame(ChatSilenceRemoval::STATUS_DISMISSED, $row->status);
        $this->assertStringContainsString('урок открыт', (string) $row->note);
    }

    public function test_telegram_refusal_marks_failed(): void
    {
        $this->banOk = false;
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();

        $this->press('csr:', self::ADMIN_TG);

        $row = ChatSilenceRemoval::sole();
        $this->assertSame(ChatSilenceRemoval::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('not enough rights', (string) $row->note);
        $this->assertSame([], app(ZapisiBanJournal::class)->all(self::CHAT));
    }

    public function test_snooze_holds_offer_for_snooze_days(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->press('css:', self::ADMIN_TG);

        $this->assertSame(ChatSilenceRemoval::STATUS_DISMISSED, ChatSilenceRemoval::sole()->status);

        Carbon::setTestNow(now()->addDays(8));
        $this->roster([self::STUDENT_TG, '666']);
        $this->message('666', now()->subDay());
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->assertSame(1, $this->proposalsSent(), 'отложено на 14 дней — через 8 не предлагаем');

        Carbon::setTestNow(now()->addDays(7));
        $this->roster([self::STUDENT_TG, '666']);
        $this->message('666', now()->subDay());
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->assertSame(2, $this->proposalsSent());
    }

    public function test_removed_student_join_request_is_not_approved_until_unbanned(): void
    {
        $this->artisan('chats:silence-removal-propose')->assertSuccessful();
        $this->press('csr:', self::ADMIN_TG);
        $this->pay($this->student, 'block_2');

        $approver = app(ChatJoinRequestApprover::class);
        $request = ['chat' => ['id' => (int) self::CHAT], 'from' => ['id' => (int) self::STUDENT_TG, 'first_name' => 'М']];

        $this->assertSame('pending', $approver->handle($request));

        ChatSilenceRemoval::query()->update(['status' => ChatSilenceRemoval::STATUS_UNBANNED]);
        $this->assertSame('approved', $approver->handle($request));
    }

    // ---------------------------------------------------------------

    /** @param list<string> $expected */
    private function assertBlocker(array $expected): void
    {
        $candidate = app(ChatSilenceRemovalEligibility::class)
            ->candidates(onlyUserId: $this->student->id)
            ->sole();

        $this->assertSame($expected, $candidate->blockers);
    }

    private function press(string $prefix, string $fromTg): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg')
            ->postJson('/api/telegram/webhook', [
                'callback_query' => [
                    'id' => 'cb-'.uniqid(),
                    'data' => $prefix.$this->student->id,
                    'from' => ['id' => (int) $fromTg],
                    'message' => [
                        'message_id' => 42,
                        'chat' => ['id' => (int) $fromTg, 'type' => 'private'],
                        'text' => 'Молчит в учебном чате',
                    ],
                ],
            ])->assertOk();
    }

    private function pay(User $user, string $tariff): void
    {
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => $tariff,
            'status' => 'paid',
            'is_conditional' => false,
        ]);
    }

    /** @param list<string> $ids */
    private function roster(array $ids, ?Carbon $fetchedAt = null): void
    {
        File::put($this->store.'/roster/'.self::CHAT.'.json', json_encode([
            'peer' => self::CHAT,
            'fetched_at' => ($fetchedAt ?? now())->toIso8601String(),
            'members' => array_map(fn (string $id) => ['id' => (int) $id, 'username' => null, 'name' => 'tg'.$id, 'is_bot' => false], $ids),
        ]));
    }

    private function message(string $tgId, Carbon $at): void
    {
        $dir = $this->store.'/corpus/'.self::CHAT;
        File::ensureDirectoryExists($dir);
        File::append($dir.'/'.$at->format('Y-m-d').'.jsonl', json_encode([
            'telegram_chat_id' => self::CHAT,
            'telegram_user_id' => $tgId,
            'text' => '…',
            'sent_at' => $at->toIso8601String(),
        ])."\n");
    }

    private function proposalsSent(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage')
            && str_contains((string) ($r->data()['reply_markup'] ?? ''), 'csr:'))->count();
    }

    private function sent(string $method): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/'.$method))->count();
    }
}
