<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Jobs\DeliverSupportReply;
use App\Models\ChatMessage;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportReplyService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SupportReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function linkedTgMessage(User $user, string $direction, string $sentAt): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9100],
            ['linked_user_id' => $user->id, 'last_message_at' => $sentAt],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9100,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => $direction,
            'text' => 'tg',
            'sent_at' => $sentAt,
        ]);
    }

    public function test_active_channel_follows_latest_message(): void
    {
        $user = User::factory()->create();
        $service = app(SupportReplyService::class);

        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => 'web', 'is_read' => false]);
        $this->assertSame(SupportReplyService::CHANNEL_WEB, $service->activeChannel($user));

        $this->linkedTgMessage($user, 'incoming', now()->addMinute()->toDateTimeString());
        $this->assertSame(SupportReplyService::CHANNEL_TELEGRAM_SUPPORT, $service->activeChannel($user));
    }

    public function test_reply_via_support_channel_records_pending_outgoing(): void
    {
        $user = User::factory()->create();
        $curator = User::factory()->create();
        $this->linkedTgMessage($user, 'incoming', now()->toDateTimeString());

        $message = app(SupportReplyService::class)->replyViaSupportChannel($user, 'Ответ куратора', $curator);

        $this->assertNotNull($message);
        $this->assertSame('outgoing', $message->direction);
        $this->assertSame($curator->id, $message->responder_user_id);
        $this->assertTrue($message->raw_payload['pending_delivery']);
        $this->assertNotNull($message->support_conversation_id);
        $this->assertSame(0, ChatMessage::where('user_id', $user->id)->where('role', 'curator')->count());
    }

    public function test_rapid_replies_to_same_chat_get_distinct_negative_ids(): void
    {
        $user = User::factory()->create();
        $curator = User::factory()->create();
        // Входящее с реальным положительным id — placeholder не должен с ним столкнуться.
        $this->linkedTgMessage($user, 'incoming', now()->toDateTimeString());

        $service = app(SupportReplyService::class);
        $first = $service->replyViaSupportChannel($user, 'Первый', $curator);
        $second = $service->replyViaSupportChannel($user, 'Второй', $curator);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->telegram_message_id, $second->telegram_message_id);
        $this->assertLessThan(0, $first->telegram_message_id);
        $this->assertLessThan(0, $second->telegram_message_id);
    }

    public function test_helpdesk_flag_on_routes_reply_to_telegram_support(): void
    {
        config(['features.support_unified_reply' => true]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $this->linkedTgMessage($student, 'incoming', now()->toDateTimeString());

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('newMessage', 'Ответ через единый инбокс')
            ->call('sendMessageToStudent');

        $this->assertDatabaseHas('telegram_support_messages', [
            'direction' => 'outgoing',
            'text' => 'Ответ через единый инбокс',
        ]);
        // Веб-ChatMessage куратора НЕ создаётся, когда разговор в TG-support.
        $this->assertSame(0, ChatMessage::where('user_id', $student->id)->where('role', 'curator')->count());
    }

    public function test_helpdesk_flag_off_keeps_web_reply(): void
    {
        config(['features.support_unified_reply' => false]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $this->linkedTgMessage($student, 'incoming', now()->toDateTimeString());

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('newMessage', 'Ответ в веб')
            ->call('sendMessageToStudent');

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'curator',
            'text' => 'Ответ в веб',
        ]);
    }

    /** Второй клик подряд не должен ставить второй джоб — троттлинг на сервере. */
    public function test_resend_is_throttled_on_a_double_click(): void
    {
        Bus::fake();
        config(['services.telegram_support.enabled' => true]);

        $message = $this->pendingOutgoingMessage(['pending_delivery' => true]);
        $service = app(SupportReplyService::class);

        $this->assertSame(SupportReplyService::RESEND_QUEUED, $service->resendPendingDelivery($message));
        $this->assertSame(SupportReplyService::RESEND_THROTTLED, $service->resendPendingDelivery($message->fresh()));

        Bus::assertDispatchedTimes(DeliverSupportReply::class, 1);
    }

    /**
     * Импортированное синком исходящее ключа pending_delivery не имеет — его
     * доставку мы не отслеживаем и досылать не берёмся.
     */
    public function test_resend_refuses_an_untracked_imported_message(): void
    {
        Bus::fake();
        config(['services.telegram_support.enabled' => true]);

        $message = $this->pendingOutgoingMessage(['from_sync' => true]);

        $this->assertSame(
            SupportReplyService::RESEND_NOT_TRACKED,
            app(SupportReplyService::class)->resendPendingDelivery($message),
        );

        Bus::assertNotDispatched(DeliverSupportReply::class);
    }

    /** @param  array<string, mixed>  $payload */
    private function pendingOutgoingMessage(array $payload): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::create(['name' => 'support-resend']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9600]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9600,
            'telegram_message_id' => -7,
            'direction' => 'outgoing',
            'role' => 'human',
            'text' => 'Ответ куратора',
            'raw_payload' => $payload,
            'sent_at' => now(),
        ]);
    }
}
