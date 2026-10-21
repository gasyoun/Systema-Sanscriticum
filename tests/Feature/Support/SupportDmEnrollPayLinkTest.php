<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\SupportAiReplyEvent;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Автоответ «запишите меня» → ссылка на оплату блока выбранной группы (20-10-2026).
 */
class SupportDmEnrollPayLinkTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 18:08:30');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_enroll_pay_link' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        $course = Course::factory()->create(['title' => 'Грамматика санскрита с нуля — набор 2026', 'is_active' => true]);
        Tariff::factory()->for($course)->block(1)->create(['price' => 8000]);
        $group = Group::create(['name' => 'Грамматика 2026 — суббота 11:00', 'status' => 'forming']);
        $course->groups()->attach($group->id);
        $other = Group::create(['name' => 'Грамматика 2026 — вторник 08:00', 'status' => 'forming']);
        $course->groups()->attach($other->id);

        $this->user = User::factory()->create();
        $this->account = TelegramSupportAccount::create(['name' => 'telegram-business', 'auto_reply_enabled' => true]);
        $this->chat = TelegramSupportChat::create([
            'telegram_chat_id' => 571472245,
            'linked_user_id' => $this->user->id,
            'last_message_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function message(string $text, string $direction = 'incoming', ?string $at = null, ?string $responder = null): TelegramSupportMessage
    {
        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => 571472245,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => $direction,
            'responder_type' => $responder,
            'text' => $text,
            'sent_at' => $at ?? now(),
        ]);
    }

    private function studentOutgoing(): ?TelegramSupportMessage
    {
        return TelegramSupportMessage::query()->where('direction', 'outgoing')->where('responder_type', 'ai')->first();
    }

    public function test_enroll_request_gets_pay_link_of_group_named_earlier(): void
    {
        $this->message('Суббота 11 - имеете в виду, в 11:00? Возможно появится группа с нуля на это время?', at: '2026-10-20 18:07:23');
        $incoming = $this->message('Можно меня тогда на нее записать?');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $out = $this->studentOutgoing();
        $this->assertNotNull($out);
        $this->assertStringContainsString('суббота 11:00', (string) $out->text);
        $this->assertStringContainsString('8 000 ₽', (string) $out->text);
        $this->assertStringContainsString('gsig=', (string) $out->text);

        $event = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->firstOrFail();
        $this->assertSame('enroll_pay_link', $event->meta['fact_type']);
    }

    public function test_human_already_answering_gets_ready_link_in_hint_not_student(): void
    {
        $this->message('Суббота 11, это и есть с нуля', 'outgoing', '2026-10-20 18:05:00', 'curator');
        $this->message('Суббота 11 - имеете в виду, в 11:00?', at: '2026-10-20 18:07:23');
        $incoming = $this->message('Можно меня тогда на нее записать?');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        $this->assertNotSame('sent', $result['status']);
        $this->assertNull($this->studentOutgoing());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Готовая ссылка на оплату'));
    }

    public function test_no_group_cue_gives_no_link(): void
    {
        $incoming = $this->message('Хочу записаться в группу с нуля, готова оплатить');

        app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        // Признака группы нет вовсе → ветка не отвечает ссылкой.
        $this->assertNull($this->studentOutgoing());
    }

    public function test_pilot_list_excludes_other_students(): void
    {
        config(['features.enroll_pilot_telegram_ids' => ['111222333']]);
        $this->message('Суббота 11 - имеете в виду, в 11:00?', at: '2026-10-20 18:07:23');
        $incoming = $this->message('Можно меня тогда на нее записать?');

        app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        $this->assertFalse(
            TelegramSupportMessage::query()->where('direction', 'outgoing')->where('text', 'like', '%gsig=%')->exists(),
        );

        // Свой аккаунт в пилоте — ссылка приходит.
        config(['features.enroll_pilot_telegram_ids' => ['571472245']]);
        $again = $this->message('Готова оплатить');
        app(SupportDmAutoReply::class)->handle($again, $this->user->id, 'private');

        $this->assertStringContainsString('gsig=', (string) $this->studentOutgoing()?->text);
    }

    public function test_flag_off_sends_no_pay_link(): void
    {
        config(['features.support_enroll_pay_link' => false]);
        $this->message('Суббота 11 - имеете в виду, в 11:00?', at: '2026-10-20 18:07:23');
        $incoming = $this->message('Можно меня тогда на нее записать?');

        app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        $this->assertFalse(
            TelegramSupportMessage::query()->where('direction', 'outgoing')->where('text', 'like', '%gsig=%')->exists(),
        );
    }

    public function test_payment_report_is_not_answered_with_link(): void
    {
        $this->message('Суббота 11 - имеете в виду, в 11:00?', at: '2026-10-20 18:07:23');
        $incoming = $this->message('Я оплатила, запишите меня');

        app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');

        $this->assertFalse(
            TelegramSupportMessage::query()->where('direction', 'outgoing')->where('text', 'like', '%gsig=%')->exists(),
        );
    }
}
