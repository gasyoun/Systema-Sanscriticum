<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Видимость доставки ответа куратора в Telegram.
 *
 * Инцидент 15-08-2026: сессия юзербота зависла, DeliverSupportReply умер по
 * таймауту, сообщение 15255 осталось pending навсегда — а куратор видел зелёное
 * «Ответ отправлен в Telegram» и ленту, в которой зависший ответ выглядел ровно
 * как доставленный. Набор закрывает оба конца: статус видно, досыл возможен.
 */
class HelpdeskDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = -100777;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram_support.enabled' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    private function telegramThread(): SupportConversation
    {
        return SupportConversation::create([
            'source_telegram_chat_id' => self::CHAT_ID,
            'source_telegram_user_id' => 4242,
            'source_telegram_message_id' => 555,
            'guest_name' => 'Маргарита (Санскрит гр.56)',
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
    }

    /**
     * Исходящий ответ куратора в треде с заданной полезной нагрузкой доставки.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function outgoing(SupportConversation $thread, ?array $payload, ?Carbon $sentAt = null): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => self::CHAT_ID],
            [
                'telegram_support_account_id' => $account->id,
                'type' => 'private',
                'title' => 'Маргарита',
            ],
        );

        return TelegramSupportMessage::create([
            'support_conversation_id' => $thread->id,
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => -1,
            'direction' => 'outgoing',
            'role' => 'human',
            'responder_type' => 'human',
            'text' => 'Выслали вам на почту данные для входа.',
            'raw_payload' => $payload,
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    /** Ровно тот случай, что случился на проде: джоб умер, ответ завис. */
    public function test_a_hung_reply_is_marked_as_not_delivered(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, [
            'pending_delivery' => true,
            'via' => 'helpdesk_unified_reply',
            'delivery_failed_at' => now()->toIso8601String(),
            'delivery_error' => 'App\Jobs\DeliverSupportReply has timed out.',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('не доставлено')
            ->assertSee('has timed out')
            ->assertSee('Дослать');
    }

    public function test_a_fresh_pending_reply_is_marked_as_waiting(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply']);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('ждёт отправки в Telegram')
            ->assertSee('Дослать');
    }

    public function test_a_long_pending_reply_is_flagged_as_taking_longer_than_usual(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, ['pending_delivery' => true], now()->subHour());

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('дольше обычного');
    }

    public function test_a_delivered_reply_offers_no_resend(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, [
            'pending_delivery' => false,
            'delivered_at' => now()->toIso8601String(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('доставлено в Telegram')
            ->assertDontSee('Дослать');
    }

    /**
     * Исходящее, которое куратор написал руками в самом Telegram, синк
     * импортировал без ключа pending_delivery. Пометить его «не доставлено» —
     * завести новую ложь вместо той, что чиним.
     */
    public function test_an_imported_outgoing_message_carries_no_delivery_badge(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, ['from_sync' => true]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            // Именно по текстам меток: класс msg-delivery есть в инлайновом CSS
            // страницы всегда, и assertDontSee по нему ничего не доказывает.
            ->assertDontSee('ждёт отправки')
            ->assertDontSee('доставлено в Telegram')
            ->assertDontSee('не доставлено')
            ->assertDontSee('Дослать');
    }

    public function test_reply_channel_badge_says_telegram_for_an_unlinked_telegram_thread(): void
    {
        $thread = $this->telegramThread();

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('Telegram-чат')
            ->assertSee('Ответить в Telegram', false)
            ->assertDontSee('Веб-чат сайта');
    }

    public function test_reply_channel_badge_says_web_for_a_real_guest_thread(): void
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-'.bin2hex(random_bytes(8)),
            'guest_name' => 'Аня',
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => null,
            'role' => 'user',
            'text' => 'Вопрос по курсу',
            'is_read' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->assertSee('Вопрос по курсу')
            ->assertSee('Веб-чат сайта')
            ->assertSee('Ответить гостю', false)
            // У chat_messages статуса доставки нет вовсе — выдумывать нельзя.
            ->assertDontSee('ждёт отправки')
            ->assertDontSee('доставлено в Telegram');
    }

    public function test_resend_rearms_the_message_and_clears_the_failure_marker(): void
    {
        $thread = $this->telegramThread();
        $message = $this->outgoing($thread, [
            'pending_delivery' => true,
            'delivery_attempts' => 3,
            'delivery_failed_at' => now()->toIso8601String(),
            'delivery_error' => 'fiber deadlock',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->call('resendDelivery', $message->id);

        $payload = $message->fresh()->raw_payload;
        $this->assertTrue($payload['pending_delivery'], 'досыл не имеет права объявлять сообщение доставленным');
        $this->assertSame(0, (int) $payload['delivery_attempts'], 'счётчик попыток обязан обнулиться, иначе дренаж отсеет сообщение');
        $this->assertArrayNotHasKey('delivery_failed_at', $payload);
        $this->assertArrayNotHasKey('delivery_error', $payload);
        $this->assertSame(1, $payload['delivery_retries']);
    }

    public function test_resend_of_a_delivered_message_does_nothing(): void
    {
        $thread = $this->telegramThread();
        $message = $this->outgoing($thread, ['pending_delivery' => false, 'delivered_at' => now()->toIso8601String()]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->call('resendDelivery', $message->id);

        $payload = $message->fresh()->raw_payload;
        $this->assertFalse($payload['pending_delivery']);
        $this->assertArrayNotHasKey('delivery_retries', $payload);
    }

    public function test_resend_changes_nothing_when_the_userbot_is_off(): void
    {
        config(['services.telegram_support.enabled' => false]);

        $thread = $this->telegramThread();
        $message = $this->outgoing($thread, ['pending_delivery' => true]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->call('resendDelivery', $message->id);

        $this->assertSame(['pending_delivery' => true], $message->fresh()->raw_payload);
    }

    /** id приходит из браузера, поэтому чужое сообщение досылать нельзя. */
    public function test_resend_refuses_a_message_from_another_dialog(): void
    {
        $opened = $this->telegramThread();
        $other = SupportConversation::create([
            'source_telegram_chat_id' => -100888,
            'guest_name' => 'Другой тред',
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
        $foreign = $this->outgoing($other, ['pending_delivery' => true]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $opened->id)
            ->call('resendDelivery', $foreign->id);

        $this->assertSame(['pending_delivery' => true], $foreign->fresh()->raw_payload);
    }

    public function test_replying_to_a_telegram_thread_promises_a_queue_not_a_delivery(): void
    {
        $thread = $this->telegramThread();
        $this->outgoing($thread, ['pending_delivery' => false, 'delivered_at' => now()->toIso8601String()]);

        Livewire::actingAs($this->admin())
            ->test(Helpdesk::class)
            ->call('selectGuest', $thread->id)
            ->set('newMessage', 'Проверьте почту, пожалуйста.')
            ->call('replyToGuest')
            ->assertSet('newMessage', '');

        $sent = TelegramSupportMessage::query()
            ->where('support_conversation_id', $thread->id)
            ->where('text', 'Проверьте почту, пожалуйста.')
            ->first();

        $this->assertNotNull($sent, 'ответ должен записаться исходящим');
        $this->assertTrue($sent->raw_payload['pending_delivery'], 'до доставки сообщение обязано числиться ожидающим');
    }
}
