<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\User;
use App\Services\StudentChatService;
use App\Services\Support\SupportConversationManager;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportConversationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_for_creates_then_reuses_single_thread(): void
    {
        $user = User::factory()->create();
        $manager = app(SupportConversationManager::class);

        $first = $manager->openFor($user);
        $second = $manager->openFor($user);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertSame(SupportConversation::STATUS_OPEN, $first->status);
    }

    public function test_closed_thread_reopens_on_new_message(): void
    {
        $user = User::factory()->create();
        $manager = app(SupportConversationManager::class);

        $thread = $manager->openFor($user);
        $manager->close($thread);
        $this->assertSame(SupportConversation::STATUS_CLOSED, $thread->fresh()->status);

        $reopened = $manager->openFor($user);
        $this->assertSame($thread->id, $reopened->id);
        $this->assertSame(SupportConversation::STATUS_OPEN, $reopened->status);
        $this->assertNull($reopened->closed_at);
    }

    public function test_student_chat_service_threads_incoming_web_message(): void
    {
        $user = User::factory()->create();

        $message = app(StudentChatService::class)->recordIncoming($user, 'Здравствуйте');

        $this->assertNotNull($message->support_conversation_id);
        $thread = SupportConversation::firstOrFail();
        $this->assertSame($user->id, $thread->user_id);
        $this->assertSame($message->support_conversation_id, $thread->id);
        $this->assertNotNull($thread->last_message_at);
    }

    public function test_telegram_sync_threads_message_for_linked_user(): void
    {
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        TelegramSupportChat::create([
            'telegram_chat_id' => 8001,
            'linked_user_id' => $user->id,
        ]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 8001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 9001,
                'direction' => 'incoming',
                'text' => 'Вопрос из Telegram',
                'sent_at' => '2026-06-28 09:00:00',
            ],
        ]);

        $thread = SupportConversation::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, $thread->telegramMessages()->count());
    }

    public function test_web_incoming_and_curator_reply_share_one_thread(): void
    {
        $user = User::factory()->create();
        $manager = app(SupportConversationManager::class);

        $incoming = app(StudentChatService::class)->recordIncoming($user, 'Вопрос');

        // Ответ куратора через тот же тред.
        $reply = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'text' => 'Ответ',
            'is_read' => true,
        ]);
        $manager->recordMessage($user, $reply, $reply->created_at);

        $this->assertDatabaseCount('support_conversations', 1);
        $this->assertSame($incoming->support_conversation_id, $reply->fresh()->support_conversation_id);
    }
}
