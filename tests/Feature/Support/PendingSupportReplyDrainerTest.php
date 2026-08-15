<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\PendingSupportReplyDrainer;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Досыл ответов куратора внутри захода синка.
 *
 * До 15-08-2026 доставку выполнял джоб в воркере Horizon и не доставил НИ ОДНОГО
 * сообщения: MadelineProto ловил там SIGTERM и разваливал событийный цикл
 * (`fiber deadlock`). Из 8670 исходящих через этот путь прошли два, доставлено
 * ноль. Теперь доставка живёт в процессе синка — короткоживущем CLI, где MTProto
 * работает каждую минуту.
 */
class PendingSupportReplyDrainerTest extends TestCase
{
    use RefreshDatabase;

    /** Синк-двойник: отдаёт заданный исход вместо похода в MTProto. */
    private function syncReturning(array $result): TelegramSupportSyncService
    {
        return new class($result) extends TelegramSupportSyncService
        {
            /** @var list<array{chat: int, text: string, reply_to: ?int}> */
            public array $sent = [];

            public function __construct(private readonly array $result) {}

            public function deliverMessage(int $chatId, string $text, ?int $replyToMsgId = null): array
            {
                $this->sent[] = ['chat' => $chatId, 'text' => $text, 'reply_to' => $replyToMsgId];

                if (($this->result['throw'] ?? null) !== null) {
                    throw new RuntimeException((string) $this->result['throw']);
                }

                return $this->result;
            }
        };
    }

    /** @param  array<string, mixed>|null  $payload */
    private function outgoing(?array $payload, int $chatId = 5001): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(['telegram_chat_id' => $chatId]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => -random_int(1, 100000),
            'direction' => 'outgoing',
            'role' => 'human',
            'text' => 'Выслали вам данные для входа',
            'raw_payload' => $payload,
            'sent_at' => now(),
        ]);
    }

    public function test_a_pending_reply_is_delivered_and_marked(): void
    {
        $message = $this->outgoing(['pending_delivery' => true, 'reply_to_msg_id' => 202240]);
        $sync = $this->syncReturning(['status' => 'ok', 'telegram_message_id' => 777]);

        $stats = app(PendingSupportReplyDrainer::class)->drain($sync);

        $this->assertSame(['attempted' => 1, 'delivered' => 1, 'failed' => 0], $stats);
        $this->assertSame([['chat' => 5001, 'text' => 'Выслали вам данные для входа', 'reply_to' => 202240]], $sync->sent);

        $fresh = $message->fresh();
        $this->assertSame(777, $fresh->telegram_message_id);
        $this->assertFalse($fresh->raw_payload['pending_delivery']);
        $this->assertArrayHasKey('delivered_at', $fresh->raw_payload);
    }

    public function test_a_delivered_reply_is_not_sent_twice(): void
    {
        $this->outgoing(['pending_delivery' => false, 'delivered_at' => now()->toIso8601String()]);
        $sync = $this->syncReturning(['status' => 'ok']);

        $stats = app(PendingSupportReplyDrainer::class)->drain($sync);

        $this->assertSame(0, $stats['attempted']);
        $this->assertSame([], $sync->sent);
    }

    /** Импорт из синка ключа pending_delivery не имеет — его не трогаем. */
    public function test_an_imported_message_is_left_alone(): void
    {
        $this->outgoing(['from_sync' => true]);
        $this->outgoing(null, 5002);
        $sync = $this->syncReturning(['status' => 'ok']);

        $this->assertSame(0, app(PendingSupportReplyDrainer::class)->drain($sync)['attempted']);
        $this->assertSame([], $sync->sent);
    }

    /**
     * ИНВАРИАНТ: неудача не снимает pending_delivery. Сообщение не доставлено, и
     * SupportObservability::delivery() обязан считать его в pending.
     */
    public function test_a_failed_attempt_keeps_the_message_pending(): void
    {
        $message = $this->outgoing(['pending_delivery' => true]);
        $sync = $this->syncReturning(['throw' => 'fiber deadlock']);

        $stats = app(PendingSupportReplyDrainer::class)->drain($sync);

        $this->assertSame(['attempted' => 1, 'delivered' => 0, 'failed' => 1], $stats);

        $payload = $message->fresh()->raw_payload;
        $this->assertTrue($payload['pending_delivery']);
        $this->assertSame(1, $payload['delivery_attempts']);
        $this->assertStringContainsString('fiber deadlock', $payload['delivery_error']);
        // Первая неудача ещё не повод рисовать «не доставлено» — попытки остались.
        $this->assertArrayNotHasKey('delivery_failed_at', $payload);
    }

    public function test_the_message_is_marked_failed_after_the_attempt_limit(): void
    {
        config(['services.telegram_support.pending_delivery_max_attempts' => 2]);

        $message = $this->outgoing(['pending_delivery' => true, 'delivery_attempts' => 1]);
        $sync = $this->syncReturning(['throw' => 'peer invalid']);

        app(PendingSupportReplyDrainer::class)->drain($sync);

        $payload = $message->fresh()->raw_payload;
        $this->assertSame(2, $payload['delivery_attempts']);
        $this->assertArrayHasKey('delivery_failed_at', $payload);
        $this->assertTrue($payload['pending_delivery'], 'исчерпанные попытки не делают сообщение доставленным');
    }

    /** Исчерпавшее лимит сообщение больше не перемалывает сессию каждый заход. */
    public function test_an_exhausted_message_is_skipped_on_later_passes(): void
    {
        config(['services.telegram_support.pending_delivery_max_attempts' => 2]);

        $this->outgoing([
            'pending_delivery' => true,
            'delivery_attempts' => 2,
            'delivery_failed_at' => now()->subHour()->toIso8601String(),
        ]);
        $sync = $this->syncReturning(['status' => 'ok']);

        $this->assertSame(0, app(PendingSupportReplyDrainer::class)->drain($sync)['attempted']);
        $this->assertSame([], $sync->sent);
    }

    /** Юзербот не готов — заход прекращаем, а не молотим остальные сообщения. */
    public function test_a_not_ready_userbot_stops_the_pass_without_marking_failures(): void
    {
        $message = $this->outgoing(['pending_delivery' => true]);
        $sync = $this->syncReturning(['status' => 'disabled']);

        $stats = app(PendingSupportReplyDrainer::class)->drain($sync);

        $this->assertSame(['attempted' => 0, 'delivered' => 0, 'failed' => 0], $stats);
        $payload = $message->fresh()->raw_payload;
        $this->assertTrue($payload['pending_delivery']);
        $this->assertArrayNotHasKey('delivery_attempts', $payload);
    }

    public function test_the_batch_size_caps_one_pass(): void
    {
        config(['services.telegram_support.pending_delivery_batch' => 2]);

        foreach ([5101, 5102, 5103] as $chatId) {
            $this->outgoing(['pending_delivery' => true], $chatId);
        }
        $sync = $this->syncReturning(['status' => 'ok', 'telegram_message_id' => 1]);

        $this->assertSame(2, app(PendingSupportReplyDrainer::class)->drain($sync)['delivered']);
        $this->assertCount(2, $sync->sent);
    }
}
