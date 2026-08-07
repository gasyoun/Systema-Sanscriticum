<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Jobs\DeliverSupportReply;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\HomeworkPauseNoteRecorder;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportReplyService;
use App\Services\Support\TechnicalIssueRouter;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\TelegramSupport\SupportContactUserAutoLinker;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\Doubles\FakeMadelineApi;
use Tests\TestCase;

class DeliverSupportReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMadelineApi::reset();
    }

    /**
     * Sync-сервис с подменённым openClient: возвращает фейковый userbot и НЕ строит
     * реальный danog\MadelineProto\Settings (его async-Logger роняет amp на
     * teardown под Windows/PHPUnit). deliverMessage() и его config-гварды при этом
     * реальные.
     */
    private function bindFakeSync(): void
    {
        $sync = new class(app(SupportDailyRollupAggregator::class), app(SupportContactUserAutoLinker::class), app(SupportConversationManager::class), app(TechnicalIssueRouter::class), app(MadelineSessionReaper::class), app(HomeworkPauseNoteRecorder::class)) extends TelegramSupportSyncService
        {
            protected function openClient(string $clientClass): object
            {
                return new FakeMadelineApi;
            }
        };

        $this->app->instance(TelegramSupportSyncService::class, $sync);
    }

    private function enableUserbot(): void
    {
        config([
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineApi::class,
        ]);
        $this->bindFakeSync();
    }

    private function pendingOutgoing(User $user): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9500,
            'linked_user_id' => $user->id,
        ]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9500,
            'telegram_message_id' => -1000,
            'direction' => 'outgoing',
            'role' => 'human',
            'text' => 'Ответ куратора',
            'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply'],
            'sent_at' => now(),
        ]);
    }

    public function test_delivers_via_userbot_and_marks_delivered(): void
    {
        $this->enableUserbot();
        $message = $this->pendingOutgoing(User::factory()->create());

        app(DeliverSupportReply::class, ['messageId' => $message->id])
            ->handle(app(TelegramSupportSyncService::class));

        $message->refresh();
        $this->assertFalse($message->raw_payload['pending_delivery']);
        $this->assertArrayHasKey('delivered_at', $message->raw_payload);
        $this->assertSame(555, $message->telegram_message_id);

        $this->assertCount(1, FakeMadelineApi::$sent);
        $this->assertSame(9500, FakeMadelineApi::$sent[0]['peer']);
        $this->assertSame('Ответ куратора', FakeMadelineApi::$sent[0]['message']);
    }

    public function test_disabled_userbot_leaves_message_pending(): void
    {
        config(['services.telegram_support.enabled' => false]);
        $message = $this->pendingOutgoing(User::factory()->create());

        (new DeliverSupportReply($message->id))->handle(app(TelegramSupportSyncService::class));

        $this->assertTrue($message->refresh()->raw_payload['pending_delivery']);
        $this->assertCount(0, FakeMadelineApi::$sent);
    }

    public function test_already_delivered_message_is_not_resent(): void
    {
        $this->enableUserbot();
        $message = $this->pendingOutgoing(User::factory()->create());
        $message->forceFill(['raw_payload' => ['pending_delivery' => false]])->save();

        (new DeliverSupportReply($message->id))->handle(app(TelegramSupportSyncService::class));

        $this->assertCount(0, FakeMadelineApi::$sent);
    }

    public function test_reply_service_dispatches_delivery_and_sends(): void
    {
        $this->enableUserbot();
        $user = User::factory()->create();
        $curator = User::factory()->create();

        // Разговор живёт в TG-support: входящее сообщение.
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9600,
            'linked_user_id' => $user->id,
            'last_message_at' => now(),
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9600,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Вопрос',
            'sent_at' => now(),
        ]);

        // QUEUE_CONNECTION=sync → job выполняется тут же (резолвит подменённый sync).
        app(SupportReplyService::class)->replyViaSupportChannel($user, 'Готовый ответ', $curator);

        $this->assertCount(1, FakeMadelineApi::$sent);
        $this->assertSame(9600, FakeMadelineApi::$sent[0]['peer']);
        $this->assertDatabaseHas('telegram_support_messages', [
            'telegram_chat_id' => 9600,
            'direction' => 'outgoing',
            'text' => 'Готовый ответ',
            'telegram_message_id' => 555,
        ]);
    }
}
