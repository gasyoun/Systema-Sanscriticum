<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\UnifiedInboxReader;
use App\Support\SupportDeliveryStatus;
use App\Support\UnifiedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedInboxReaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тред без users-записи читается forConversation(), а не forUser(). Раньше
     * Helpdesk собирал для него несохранённые ChatMessage и терял raw_payload —
     * вместе со статусом доставки (инцидент 15-08-2026).
     */
    public function test_for_conversation_reads_a_telegram_thread_with_delivery_status(): void
    {
        $thread = SupportConversation::create([
            'source_telegram_chat_id' => 9300,
            'guest_name' => 'Маргарита',
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9300]);

        $base = [
            'support_conversation_id' => $thread->id,
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9300,
        ];

        TelegramSupportMessage::create($base + [
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Не могу войти',
            'sent_at' => '2026-08-15 11:05:00',
        ]);
        TelegramSupportMessage::create($base + [
            'telegram_message_id' => -1,
            'direction' => 'outgoing',
            'role' => 'human',
            'text' => 'Выслали данные',
            'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply'],
            'sent_at' => '2026-08-15 12:20:00',
        ]);

        $stream = app(UnifiedInboxReader::class)->forConversation($thread);

        $this->assertCount(2, $stream);
        $this->assertNull($stream[0]->delivery, 'у входящего статуса доставки быть не может');
        $this->assertSame(SupportDeliveryStatus::STATE_PENDING, $stream[1]->delivery?->state);
        $this->assertTrue($stream[1]->delivery->canRetry());
    }

    public function test_for_conversation_reads_a_web_guest_thread_without_delivery_status(): void
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

        $stream = app(UnifiedInboxReader::class)->forConversation($thread);

        $this->assertCount(1, $stream);
        $this->assertSame(UnifiedMessage::CHANNEL_WEB, $stream[0]->channel);
        // У chat_messages статуса доставки нет вовсе — и выдумывать его нельзя.
        $this->assertNull($stream[0]->delivery);
    }

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

    // --- H1200: Jivo-паритет S5, VK/TG-student-bot разбейджены как отдельные каналы ---

    public function test_null_source_defaults_to_web_channel(): void
    {
        $user = User::factory()->create();
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Старая строка без source',
            'is_read' => false,
        ]);

        $unified = UnifiedMessage::fromChatMessage($message);

        $this->assertSame(UnifiedMessage::CHANNEL_WEB, $unified->channel);
        $this->assertSame('Кабинет', $unified->channelLabel());
    }

    public function test_vk_source_maps_to_vk_channel(): void
    {
        $user = User::factory()->create();
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Вопрос из ВК',
            'is_read' => false,
            'source' => 'vk',
        ]);

        $unified = UnifiedMessage::fromChatMessage($message);

        $this->assertSame(UnifiedMessage::CHANNEL_VK, $unified->channel);
        $this->assertSame('ВКонтакте', $unified->channelLabel());
    }

    public function test_telegram_bot_source_maps_to_telegram_bot_channel(): void
    {
        $user = User::factory()->create();
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Вопрос из TG-бота',
            'is_read' => false,
            'source' => 'telegram_bot',
        ]);

        $unified = UnifiedMessage::fromChatMessage($message);

        $this->assertSame(UnifiedMessage::CHANNEL_TELEGRAM_BOT, $unified->channel);
        $this->assertSame('Telegram-бот', $unified->channelLabel());
        // Отличен от импортированного TG-support-канала (тот CHANNEL_TELEGRAM).
        $this->assertNotSame(UnifiedMessage::CHANNEL_TELEGRAM, $unified->channel);
    }

    public function test_reader_distinguishes_all_four_channels_for_one_user(): void
    {
        $user = User::factory()->create();

        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => 'Веб', 'is_read' => false])
            ->forceFill(['created_at' => '2026-07-18 08:00:00'])->save();
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => 'ВК', 'is_read' => false, 'source' => 'vk'])
            ->forceFill(['created_at' => '2026-07-18 08:01:00'])->save();
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => 'TG-бот', 'is_read' => false, 'source' => 'telegram_bot'])
            ->forceFill(['created_at' => '2026-07-18 08:02:00'])->save();

        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9010, 'linked_user_id' => $user->id]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9010,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'TG-support импорт',
            'sent_at' => '2026-07-18 08:03:00',
        ]);

        $stream = app(UnifiedInboxReader::class)->forUser($user);

        $this->assertCount(4, $stream);
        $this->assertSame(
            [UnifiedMessage::CHANNEL_WEB, UnifiedMessage::CHANNEL_VK, UnifiedMessage::CHANNEL_TELEGRAM_BOT, UnifiedMessage::CHANNEL_TELEGRAM],
            $stream->pluck('channel')->all(),
        );
    }
}
