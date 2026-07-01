<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\UnifiedInboxReader;
use App\Support\UnifiedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedInboxReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_message_roles_map_to_direction_and_responder_type(): void
    {
        $user = User::factory()->create();
        $curator = User::factory()->create();

        $incoming = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Здравствуйте',
            'is_read' => false,
        ]);
        $bot = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => 'Отвечает ИИ',
            'is_read' => true,
            'ai_state' => 'sent',
        ]);
        $curatorMsg = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'answered_by' => $curator->id,
            'text' => 'Отвечает человек',
            'is_read' => true,
        ]);

        $in = UnifiedMessage::fromChatMessage($incoming);
        $this->assertSame(UnifiedMessage::CHANNEL_WEB, $in->channel);
        $this->assertSame(UnifiedMessage::DIRECTION_INCOMING, $in->direction);
        $this->assertNull($in->responderType);
        $this->assertFalse($in->isRead);

        $ai = UnifiedMessage::fromChatMessage($bot);
        $this->assertSame(UnifiedMessage::DIRECTION_OUTGOING, $ai->direction);
        $this->assertSame(UnifiedMessage::RESPONDER_AI, $ai->responderType);
        $this->assertSame('sent', $ai->aiState);

        $human = UnifiedMessage::fromChatMessage($curatorMsg);
        $this->assertSame(UnifiedMessage::DIRECTION_OUTGOING, $human->direction);
        $this->assertSame(UnifiedMessage::RESPONDER_HUMAN, $human->responderType);
        $this->assertSame($curator->id, $human->responderUserId);
    }

    public function test_telegram_support_message_maps_via_linked_chat(): void
    {
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 7001,
            'linked_user_id' => $user->id,
        ]);

        $message = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 7001,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'responder_type' => null,
            'text' => 'Вопрос из Telegram',
            'sent_at' => '2026-06-28 09:00:00',
        ]);

        $unified = UnifiedMessage::fromTelegramSupportMessage($message->load('chat', 'contact'));

        $this->assertSame(UnifiedMessage::CHANNEL_TELEGRAM, $unified->channel);
        $this->assertSame($user->id, $unified->userId);
        $this->assertSame(UnifiedMessage::DIRECTION_INCOMING, $unified->direction);
        $this->assertTrue($unified->isRead);
    }

    public function test_reader_merges_both_stores_chronologically_for_user(): void
    {
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 7002,
            'linked_user_id' => $user->id,
        ]);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 5002,
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $user->id,
        ]);

        // Веб-сообщение — раньше по времени. created_at не mass-assignable —
        // задаём явно после создания, иначе Eloquent проставит now().
        $webMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Веб-сообщение',
            'is_read' => false,
        ]);
        $webMessage->forceFill(['created_at' => '2026-06-28 08:00:00'])->save();

        // TG-сообщение — позже.
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => 7002,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'TG-сообщение',
            'sent_at' => '2026-06-28 09:00:00',
        ]);

        // Сообщение другого пользователя не должно попасть в выборку.
        $other = User::factory()->create();
        ChatMessage::create([
            'user_id' => $other->id,
            'role' => 'user',
            'text' => 'Чужое',
            'is_read' => false,
        ]);

        $stream = app(UnifiedInboxReader::class)->forUser($user);

        $this->assertCount(2, $stream);
        $this->assertSame('Веб-сообщение', $stream[0]->text);
        $this->assertSame(UnifiedMessage::CHANNEL_WEB, $stream[0]->channel);
        $this->assertSame('TG-сообщение', $stream[1]->text);
        $this->assertSame(UnifiedMessage::CHANNEL_TELEGRAM, $stream[1]->channel);
    }
}
