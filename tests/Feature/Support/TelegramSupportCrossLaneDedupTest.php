<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\Concerns\TracksPendingDelivery;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кросс-полосный дедуп поддержки: с 27-09-2026 аккаунт полосы support подключён
 * к ORS через Telegram Business, поэтому одно сообщение Telegram приходит
 * дважды — business-вебхуком сразу и Madeline-синком минутой позже. Раньше
 * дедуп был внутриполосным, и лента хелпдеска показывала каждый входящий
 * дважды, а исходящие ответы куратора в непривязанных чатах не показывала
 * вовсе (conv=NULL у полосы business).
 */
class TelegramSupportCrossLaneDedupTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 105_467_2947;

    private const OWNER_TG_ID = 548_729_3147;

    public function test_same_message_from_two_lanes_is_stored_once(): void
    {
        config([
            'features.support_dm_auto_reply' => false,
            'features.support_unlinked_dm_threads' => false,
        ]);

        $sync = app(TelegramSupportSyncService::class);

        // Полоса business: вебхук привозит сообщение первым, за секунды.
        $sync->syncNormalizedMessages([
            [
                'telegram_chat_id' => self::CHAT_ID,
                'telegram_message_id' => 206_249,
                'telegram_user_id' => 777_001,
                'direction' => 'incoming',
                'text' => 'Здравствуйте, мне нужно оплатить 12 блок',
                'sent_at' => '2026-10-07 11:18:24',
                'chat_type' => 'private',
                'contact_name' => 'Людмила',
                'business_connection_id' => 'bconn-1',
            ],
        ], 'telegram-business');

        // Полоса support: тот же чат и message_id минутой позже.
        $sync->syncNormalizedMessages([
            [
                'telegram_chat_id' => self::CHAT_ID,
                'telegram_message_id' => 206_249,
                'telegram_user_id' => 777_001,
                'direction' => 'incoming',
                'text' => 'Здравствуйте, мне нужно оплатить 12 блок',
                'sent_at' => '2026-10-07 11:18:24',
                'chat_type' => 'private',
                'contact_name' => 'Людмила',
                'raw_madeline' => ['id' => 206_249],
            ],
        ], 'support');

        $this->assertSame(1, TelegramSupportMessage::query()->count());

        $row = TelegramSupportMessage::query()->firstOrFail();
        // Строкой владеет первая полоса; её payload не перезаписан вторым.
        $this->assertSame(
            TelegramSupportAccount::query()->where('name', 'telegram-business')->value('id'),
            (int) $row->telegram_support_account_id,
        );
        $this->assertSame('bconn-1', (string) (($row->raw_payload ?? [])['business_connection_id'] ?? ''));
        $this->assertArrayNotHasKey('raw_madeline', $row->raw_payload ?? []);
    }

    public function test_curator_reply_from_telegram_attaches_to_unlinked_thread(): void
    {
        $thread = SupportConversation::query()->create([
            'status' => SupportConversation::STATUS_OPEN,
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'source_telegram_chat_id' => self::CHAT_ID,
            'source_chat_type' => 'private',
            'last_message_at' => now(),
        ]);

        // Ответ куратора, набранный прямо в Telegram (не через хелпдеск).
        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => self::CHAT_ID,
                'telegram_message_id' => 206_250,
                'telegram_user_id' => null,
                'direction' => 'outgoing',
                'text' => '🐢 Ссылка для оплаты https://samskrte.ru/checkout/4602',
                'sent_at' => '2026-10-07 11:26:23',
                'chat_type' => 'private',
            ],
        ], 'telegram-business');

        $reply = TelegramSupportMessage::query()
            ->where('telegram_message_id', 206_250)
            ->firstOrFail();

        $this->assertSame($thread->id, $reply->support_conversation_id);
    }

    public function test_delivered_placeholder_merges_into_twin_row_from_another_lane(): void
    {
        $thread = SupportConversation::query()->create([
            'status' => SupportConversation::STATUS_OPEN,
            'queue' => SupportConversation::QUEUE_TECHNICAL,
            'source_telegram_chat_id' => self::CHAT_ID,
            'source_chat_type' => 'private',
            'last_message_at' => now(),
        ]);

        $chat = TelegramSupportChat::query()->create([
            'telegram_chat_id' => self::CHAT_ID,
            'type' => 'private',
        ]);

        // Юзербот доставил ответ, поставленный в очередь из хелпдеска:
        // placeholder уже привязан к треду и знает куратора.
        $pending = TelegramSupportMessage::query()->create([
            'telegram_support_account_id' => TelegramSupportAccount::query()->create([
                'name' => 'support', 'is_enabled' => true,
            ])->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => -1,
            'direction' => 'outgoing',
            'role' => 'human',
            'responder_type' => 'human',
            'support_conversation_id' => $thread->id,
            'text' => '🐢 Ответ куратора',
            'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply'],
            'sent_at' => now()->subMinute(),
        ]);

        // Другая полоса успела записать то же сообщение эхом вебхука.
        $twin = TelegramSupportMessage::query()->create([
            'telegram_support_account_id' => TelegramSupportAccount::query()->create([
                'name' => 'telegram-business', 'is_enabled' => true,
            ])->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => 206_255,
            'direction' => 'outgoing',
            'text' => '🐢 Ответ куратора',
            'raw_payload' => ['business_connection_id' => 'bconn-1'],
            'sent_at' => now()->subMinute(),
        ]);

        $caller = new class
        {
            use TracksPendingDelivery;

            public function mark(TelegramSupportMessage $message, array $payload, mixed $telegramMessageId): void
            {
                $this->markDelivered($message, $payload, $telegramMessageId);
            }
        };

        $caller->mark($pending, $pending->raw_payload, 206_255);

        $this->assertDatabaseMissing('telegram_support_messages', ['id' => $pending->id]);

        $twin->refresh();
        $this->assertSame(206_255, (int) $twin->telegram_message_id);
        $this->assertFalse((bool) (($twin->raw_payload ?? [])['pending_delivery'] ?? true));
        $this->assertNotNull(($twin->raw_payload ?? [])['delivered_at']);
        // Привязка к треду и атрибуция переехали в выжившую строку.
        $this->assertSame($thread->id, $twin->support_conversation_id);
        $this->assertSame('human', $twin->role);
        // Payload полосы-владельца не потерян.
        $this->assertSame('bconn-1', (string) (($twin->raw_payload ?? [])['business_connection_id'] ?? ''));
    }

    public function test_placeholder_ids_of_pending_replies_are_not_deduplicated_across_accounts(): void
    {
        $chat = TelegramSupportChat::query()->create([
            'telegram_chat_id' => self::CHAT_ID,
            'type' => 'private',
        ]);

        // Два аккаунта, у каждого свой ожидающий ответ с placeholder -1:
        // это разные ответы, а не дубли одного сообщения.
        foreach (['support', 'telegram-business'] as $name) {
            $account = TelegramSupportAccount::query()->create(['name' => $name, 'is_enabled' => true]);
            TelegramSupportMessage::query()->create([
                'telegram_support_account_id' => $account->id,
                'telegram_support_chat_id' => $chat->id,
                'telegram_chat_id' => self::CHAT_ID,
                'telegram_message_id' => -1,
                'direction' => 'outgoing',
                'text' => 'Ответ '.$name,
                'raw_payload' => ['pending_delivery' => true],
                'sent_at' => now(),
            ]);
        }

        $this->assertSame(2, TelegramSupportMessage::query()->where('telegram_message_id', -1)->count());
    }
}
