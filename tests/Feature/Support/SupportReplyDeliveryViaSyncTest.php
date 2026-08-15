<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use danog\MadelineProto\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\Doubles\FakeMadelineProtoClient;
use Tests\TestCase;

/**
 * Инвариант доставки юзерботом: на весь заход синка — чтение плюс досыл
 * ответов куратора — создаётся РОВНО ОДИН MadelineProto-клиент.
 *
 * Каждый лишний `new API()` — это лишний Logger-пайп в статическом
 * Logger::$closePromises и fiber deadlock на shutdown. Именно так доставка не
 * работала никогда (0 из всех попыток до 15-08-2026): deliverMessage() открывал
 * нового клиента на каждое сообщение. Счётчик $constructions в двойнике меряет
 * ровно создания объектов, а не вызовы start().
 */
class SupportReplyDeliveryViaSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = 5001;

    protected function setUp(): void
    {
        parent::setUp();

        FakeMadelineProtoClient::reset();

        config([
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
        ]);
    }

    /**
     * Реальный путь синка может инстанцировать danog\MadelineProto\Settings —
     * пакет опциональная зависимость, без него набор пропускаем (та же
     * дисциплина, что в TelegramSupportAnalyticsTest).
     */
    private function skipWithoutMadelineProto(): void
    {
        if (! class_exists(Settings::class)) {
            $this->markTestSkipped('danog/madelineproto не установлен (опциональная зависимость).');
        }
    }

    private function pendingReply(): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => self::PEER]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => self::PEER,
            'telegram_message_id' => -1,
            'direction' => 'outgoing',
            'role' => 'human',
            'responder_type' => 'human',
            'text' => 'Выслали вам данные для входа',
            'raw_payload' => ['pending_delivery' => true, 'reply_to_msg_id' => 4242],
            'sent_at' => now(),
        ]);
    }

    public function test_sync_run_with_pending_reply_creates_exactly_one_client_and_delivers(): void
    {
        $this->skipWithoutMadelineProto();

        $message = $this->pendingReply();
        FakeMadelineProtoClient::$histories = [
            self::PEER => [
                ['id' => 7, 'date' => now()->timestamp, 'message' => 'Здравствуйте, не могу войти'],
            ],
        ];

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['delivered']);

        // Сердце инварианта: чтение И отправка прошли через ОДИН объект клиента.
        $this->assertSame(1, FakeMadelineProtoClient::$constructions, 'за заход обязан создаваться ровно один клиент');

        $this->assertCount(1, FakeMadelineProtoClient::$sent);
        $this->assertSame(self::PEER, FakeMadelineProtoClient::$sent[0]['peer']);
        $this->assertSame('Выслали вам данные для входа', FakeMadelineProtoClient::$sent[0]['message']);
        // Формат MadelineProto 8.x: конструктор inputReplyToMessage, а не
        // плоский reply_to_msg_id — тот двойник отвергает, как реальный API.
        $this->assertSame(
            ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => 4242],
            FakeMadelineProtoClient::$sent[0]['reply_to'],
        );

        $fresh = $message->fresh();
        $this->assertSame(555, $fresh->telegram_message_id);
        $this->assertFalse($fresh->raw_payload['pending_delivery']);
        $this->assertArrayHasKey('delivered_at', $fresh->raw_payload);
    }

    public function test_sync_with_no_new_messages_still_drains_on_the_same_single_client(): void
    {
        $this->skipWithoutMadelineProto();

        $this->pendingReply();
        FakeMadelineProtoClient::$histories = [];

        $result = app(TelegramSupportSyncService::class)->sync();

        // Ранняя ветка sync() («новых сообщений нет») тоже досылает — и тоже
        // одним клиентом, открытым чтением.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['delivered']);
        $this->assertSame(1, FakeMadelineProtoClient::$constructions);
        $this->assertCount(1, FakeMadelineProtoClient::$sent);
    }

    public function test_auth_restart_retry_delivers_pending_replies_with_a_fresh_client(): void
    {
        $this->skipWithoutMadelineProto();

        $this->pendingReply();
        FakeMadelineProtoClient::$histories = [
            self::PEER => [
                ['id' => 7, 'date' => now()->timestamp, 'message' => 'Вопрос'],
            ],
        ];
        FakeMadelineProtoClient::$startFailures = ['AUTH_RESTART'];

        $result = app(TelegramSupportSyncService::class)->sync();

        // Семантика ретрая сохранена: после AUTH_RESTART создаётся СВЕЖИЙ клиент
        // (второй и последний), и досыл идёт уже через него.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['delivered']);
        $this->assertSame(2, FakeMadelineProtoClient::$constructions);
        $this->assertCount(1, FakeMadelineProtoClient::$sent);
    }
}
