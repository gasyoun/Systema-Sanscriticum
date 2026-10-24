<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Enums\ChatRemovalStatus;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendGroupChatInviteJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Course;
use App\Models\CourseDebtChatRemoval;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * После оплаты — ссылка-заявка в чат группы, заявку одобряет @zapisi_ORSbot
 * только оплатившему (features.paid_chat_join_request, 20-10-2026).
 */
class PaidChatJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '-1001234567890';

    private Course $course;

    private Group $group;

    private bool $linkOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        config([
            'features.paid_chat_join_request' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake([
            'api.telegram.org/botZAPISI-TOKEN/createChatInviteLink' => fn () => $this->linkOk
                ? Http::response(['ok' => true, 'result' => ['invite_link' => 'https://t.me/+JOINLINK']])
                : Http::response(['ok' => false, 'description' => 'not enough rights']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.63']);
        $this->group = Group::create(['name' => 'Грамматика по Кочергиной гр.63', 'status' => 'active', 'telegram_chat_id' => self::CHAT]);
        $this->course->groups()->attach($this->group->id);
    }

    private function paid(User $user, bool $conditional = false): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => $conditional,
        ]);
    }

    public function test_paid_payment_dispatches_invite_job(): void
    {
        Queue::fake();
        $this->paid(User::factory()->create(['telegram_id' => 555]));

        Queue::assertPushed(SendGroupChatInviteJob::class);
    }

    public function test_conditional_or_flag_off_dispatches_nothing(): void
    {
        Queue::fake();
        $this->paid(User::factory()->create(), conditional: true);
        config(['features.paid_chat_join_request' => false]);
        $this->paid(User::factory()->create());

        Queue::assertNotPushed(SendGroupChatInviteJob::class);
    }

    public function test_job_creates_join_link_once_and_sends_it(): void
    {
        Queue::fake();
        $user = User::factory()->create(['telegram_id' => 555]);
        $payment = $this->paid($user);

        $this->runInviteJob($payment);
        $this->runInviteJob($payment);

        $this->assertSame('https://t.me/+JOINLINK', $this->group->fresh()->telegram_join_link);
        $this->assertNotNull($payment->fresh()->chat_invite_sent_at);
        // Оплата сама шлёт «Оплата получена»; ссылка-заявка — ровно одна, второй прогон молчит.
        $this->assertCount(1, Queue::pushed(SendTelegramMessageJob::class, fn ($job) => str_contains($job->text, 'https://t.me/+JOINLINK')));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createChatInviteLink') && $r['creates_join_request'] === true);
    }

    public function test_link_failure_releases_claim_and_alerts(): void
    {
        Queue::fake();
        $this->linkOk = false;
        $payment = $this->paid(User::factory()->create(['telegram_id' => 555]));

        $this->runInviteJob($payment);

        $this->assertNull($payment->fresh()->chat_invite_sent_at);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'not enough rights'));
    }

    private function runInviteJob(Payment $payment): void
    {
        app()->call([new SendGroupChatInviteJob($payment->id), 'handle']);
    }

    private function joinRequest(int $fromId): void
    {
        (new ProcessTelegramZapisiUpdate([
            'update_id' => random_int(1, 1_000_000),
            'chat_join_request' => [
                'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup'],
                'from' => ['id' => $fromId, 'first_name' => 'Маргарита'],
                'date' => now()->timestamp,
            ],
        ]))->handle();
    }

    public function test_paid_member_is_approved(): void
    {
        $user = User::factory()->create(['telegram_id' => 555]);
        $user->groups()->attach($this->group->id);

        $this->joinRequest(555);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest') && (string) $r['user_id'] === '555');
    }

    public function test_member_linked_only_via_support_contact_is_approved(): void
    {
        $user = User::factory()->create(['telegram_id' => null]);
        $user->groups()->attach($this->group->id);
        TelegramSupportContact::create(['telegram_user_id' => 777, 'linked_user_id' => $user->id, 'name' => 'Студентка']);

        $this->joinRequest(777);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest'));
    }

    public function test_stranger_is_not_approved_and_admins_alerted(): void
    {
        $this->joinRequest(999);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'declineChatJoinRequest'));
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'не одобрена автоматически'));
    }

    public function test_removed_for_debt_is_not_approved(): void
    {
        $user = User::factory()->create(['telegram_id' => 555]);
        $user->groups()->attach($this->group->id);
        CourseDebtChatRemoval::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'telegram_chat_id' => self::CHAT,
            'status' => ChatRemovalStatus::Removed,
            'removed_at' => now()->subDay(),
        ]);

        $this->joinRequest(555);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest'));
    }

    public function test_pilot_list_limits_invite_and_approval(): void
    {
        Queue::fake();
        config(['features.enroll_pilot_telegram_ids' => ['555']]);
        $pilot = User::factory()->create(['telegram_id' => 555]);
        $pilot->groups()->attach($this->group->id);
        $student = User::factory()->create(['telegram_id' => 666]);
        $student->groups()->attach($this->group->id);

        $this->runInviteJob($this->paid($student));
        $this->joinRequest(666);

        // Не из пилота — ни ссылки, ни одобрения, ни алерта: как при выключенном флаге.
        $this->assertCount(0, Queue::pushed(SendTelegramMessageJob::class, fn ($job) => str_contains($job->text, 'JOINLINK')));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest'));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'не одобрена автоматически'));

        $this->runInviteJob($this->paid($pilot));
        $this->joinRequest(555);

        $this->assertCount(1, Queue::pushed(SendTelegramMessageJob::class, fn ($job) => str_contains($job->text, 'JOINLINK')));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest') && (string) $r['user_id'] === '555');
    }

    public function test_flag_off_ignores_join_request(): void
    {
        config(['features.paid_chat_join_request' => false]);
        $user = User::factory()->create(['telegram_id' => 555]);
        $user->groups()->attach($this->group->id);

        $this->joinRequest(555);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'approveChatJoinRequest'));
    }
}
