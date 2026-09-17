<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Services\Support\Concerns\TracksPendingDelivery;
use App\Services\Support\PendingSupportReplyDrainer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * H5065 — досыл ответов полосы Telegram Business ОТ ИМЕНИ аккаунта.
 *
 * Устройство намеренно повторяет MadelineProto-дренаж
 * ({@see PendingSupportReplyDrainer}) в той части, которая
 * про признак «ждёт доставки», и отличается ровно в одном: там доставку
 * выполняет живая MTProto-сессия внутри процесса синка, а здесь — обычный
 * HTTPS-вызов Bot API, поэтому дренаж может жить и в очереди, и в кроне.
 * Общий контракт pending вынесен в {@see TracksPendingDelivery}.
 *
 * ОТКУДА БЕРЁТСЯ ПРАВО ОТВЕТИТЬ. Параметр `business_connection_id` не лежит на
 * чате (схему support-таблиц полоса не трогает). Он восстанавливается из
 * подключения, которое ПРИНЕСЛО вопрос студента: у входящего business_message
 * этот id записан в raw_payload. Поэтому очередь ответов неотделима от истории
 * входящих — если бы права кэшировались на чате, отзыв can_reply владельцем
 * остался бы незамеченным.
 */
final class BusinessSupportReplyDrainer
{
    use TracksPendingDelivery;

    public function __construct(
        private readonly TelegramBusinessSender $sender,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.telegram_business_bot', false);
    }

    /**
     * @return array{attempted: int, delivered: int, failed: int, skipped: int}
     */
    public function drain(): array
    {
        $stats = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $this->isEnabled()) {
            return $stats;
        }

        $account = $this->account();
        if ($account === null) {
            return $stats;
        }

        $batch = max(1, (int) config('services.telegram_business.pending_delivery_batch', 20));
        $maxAttempts = max(1, (int) config('services.telegram_business.pending_delivery_max_attempts', 3));

        foreach ($this->pendingOutgoing((int) $account->id, $batch) as $message) {
            $payload = $message->raw_payload ?? [];

            // Исчерпал попытки — ждём ручного досыла: он снимает пометку и
            // обнуляет счётчик. Иначе безнадёжный чат перемалывался бы каждую
            // минуту.
            if ((int) ($payload['delivery_attempts'] ?? 0) >= $maxAttempts) {
                $stats['skipped']++;

                continue;
            }

            $connectionId = $this->connectionIdFor($message);
            if ($connectionId === null) {
                $stats['failed']++;
                $this->markAttemptFailed(
                    $message,
                    $payload,
                    new RuntimeException('нет business_connection_id у чата: полоса не знает, от чьего имени отвечать'),
                    $maxAttempts,
                );

                continue;
            }

            $connection = TelegramBusinessConnection::usable($connectionId);
            if ($connection === null) {
                $stats['failed']++;
                $this->markAttemptFailed(
                    $message,
                    $payload,
                    new RuntimeException('подключение '.$connectionId.' не готово: is_enabled/can_reply сняты владельцем'),
                    $maxAttempts,
                );

                continue;
            }

            $stats['attempted']++;

            $replyTo = isset($payload['reply_to_msg_id']) ? (int) $payload['reply_to_msg_id'] : null;
            $result = $this->sender->send(
                $connectionId,
                (int) $message->telegram_chat_id,
                (string) $message->text,
                $replyTo && $replyTo > 0 ? $replyTo : null,
            );

            if ($result['status'] === 'suppressed') {
                // Кто-то уже отправил этот текст в этот чат: не ошибка, не
                // дубль, ничего не помечаем — следующая досылка увидит то же.
                $stats['attempted']--;

                continue;
            }

            if ($result['status'] === 'ok') {
                $this->markDelivered($message, $payload, $result['message_id']);
                $stats['delivered']++;

                continue;
            }

            $stats['failed']++;
            $this->markAttemptFailed($message, $payload, new RuntimeException((string) $result['error']), $maxAttempts);
        }

        if ($stats['delivered'] > 0 || $stats['failed'] > 0) {
            Log::info('Досыл ответов полосы Telegram Business', $stats);
        }

        return $stats;
    }

    private function account(): ?TelegramSupportAccount
    {
        $name = (string) config('services.telegram_business.account_name', 'telegram-business');

        return TelegramSupportAccount::query()->where('name', $name)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function connectionIdFor(TelegramSupportMessage $message, array $payload = []): ?string
    {
        $payload = $payload === [] ? ($message->raw_payload ?? []) : $payload;

        $own = (string) ($payload['business_connection_id'] ?? '');
        if ($own !== '') {
            return $own;
        }

        // Право ответить выводится из подключения, которое принесло вопрос
        // студента: смотрим последние входящие этого чата. Ограничение окном в
        // 20 строк — чтобы длинный чат не тянул всю историю на каждый досыл.
        return TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $message->telegram_support_chat_id)
            ->where('direction', 'incoming')
            ->whereNotNull('raw_payload')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static fn (TelegramSupportMessage $m): string => (string) (($m->raw_payload ?? [])['business_connection_id'] ?? ''))
            ->first(static fn (string $id): bool => $id !== '');
    }
}
