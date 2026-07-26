<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Доставка ответа куратора в импортированный TG-support через userbot
 * (MadelineProto). Сообщение уже записано как исходящее с pending_delivery=true
 * ([[SupportReplyService]]); здесь оно реально отправляется, после чего запись
 * помечается доставленной и получает настоящий telegram_message_id.
 *
 * Идемпотентно: если pending_delivery уже снят — выходим. Если окружение не
 * готово (userbot выключен/не настроен) — тихо оставляем pending, без ретрая;
 * реальные ошибки отправки пробрасываются и ретраятся очередью.
 */
class DeliverSupportReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $messageId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TelegramSupportSyncService $sync): void
    {
        $message = TelegramSupportMessage::find($this->messageId);
        if (! $message) {
            return;
        }

        $payload = $message->raw_payload ?? [];
        if (empty($payload['pending_delivery'])) {
            return; // уже доставлено
        }

        $replyTo = isset($payload['reply_to_msg_id']) ? (int) $payload['reply_to_msg_id'] : null;
        $result = $sync->deliverMessage(
            (int) $message->telegram_chat_id,
            (string) $message->text,
            $replyTo && $replyTo > 0 ? $replyTo : null,
        );

        if (($result['status'] ?? null) !== 'ok') {
            // Окружение не готово (disabled/unconfigured/missing) — оставляем pending, не ретраим.
            Log::info('DeliverSupportReply: userbot не готов, оставляем pending', [
                'message_id' => $message->id,
                'status' => $result['status'] ?? 'unknown',
            ]);

            return;
        }

        $payload['pending_delivery'] = false;
        $payload['delivered_at'] = now()->toIso8601String();

        $update = ['raw_payload' => $payload];
        if (! empty($result['telegram_message_id'])) {
            $update['telegram_message_id'] = (int) $result['telegram_message_id'];
        }

        $message->forceFill($update)->save();
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('DeliverSupportReply failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
