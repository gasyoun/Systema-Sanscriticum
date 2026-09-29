<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Services\Support\Concerns\TracksPendingDelivery;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Досыл ответов куратора, ждущих доставки, — ВНУТРИ процесса синка.
 *
 * ПОЧЕМУ НЕ ОЧЕРЕДЬ. До 15-08-2026 доставку выполнял джоб DeliverSupportReply в
 * воркере Horizon, и она не сработала НИ РАЗУ: из 8670 исходящих через этот путь
 * прошли два, доставлено ноль. Событийный цикл MadelineProto не выживает в
 * долгоживущем воркере — он ловил `Amp\SignalException: SIGTERM received`, а
 * следом `Event loop terminated without resuming the current suspension (fiber
 * deadlock)`. Тот же deliverMessage() из процесса `telegram-support:sync`
 * работает: это короткоживущий CLI-процесс, и MTProto-сессия у него своя.
 *
 * Отсюда правило: НИКОГДА не открывайте MadelineProto из воркера очереди —
 * ни здесь, ни в новых джобах. Единственный владелец сессии — процесс синка.
 *
 * Задержка доставки становится равна периоду синка (минута) вместо секунд. Это
 * сознательный размен: минута ожидания против доставки, которой нет вовсе.
 *
 * H5065: признак «ждёт доставки» и его разметка переехали в общий трейт
 * {@see TracksPendingDelivery} — тот же контракт читает дренаж полосы Telegram
 * Business. Копия этих методов разошлась бы молча.
 */
class PendingSupportReplyDrainer
{
    use TracksPendingDelivery;

    /**
     * Разослать ждущие ответы. Вызывается синком после успешного захода, когда
     * сессия заведомо жива.
     *
     * H3380: $accountId скоупит дрен ответами своего аккаунта. Открыть чужой
     * peer через чужую сессию нельзя (peer-резолв ляжет, attempts сгорят) —
     * каждый аккаунт досылает только своё, своим telegram-support:sync.
     *
     * @return array{attempted: int, delivered: int, failed: int}
     */
    public function drain(TelegramSupportSyncService $sync, ?int $accountId = null): array
    {
        $batch = max(1, (int) config('services.telegram_support.pending_delivery_batch', 20));
        $maxAttempts = max(1, (int) config('services.telegram_support.pending_delivery_max_attempts', 3));

        $pending = $this->pendingOutgoing($accountId, $batch);

        $stats = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];

        foreach ($pending as $message) {
            $payload = $message->raw_payload ?? [];

            // Исчерпал попытки — ждём ручного досыла: он снимает пометку и
            // обнуляет счётчик. Иначе безнадёжный чат перемалывал бы сессию
            // на каждом заходе синка.
            if ((int) ($payload['delivery_attempts'] ?? 0) >= $maxAttempts) {
                continue;
            }

            $stats['attempted']++;

            try {
                $replyTo = isset($payload['reply_to_msg_id']) ? (int) $payload['reply_to_msg_id'] : null;

                $result = $sync->deliverMessage(
                    (int) $message->telegram_chat_id,
                    (string) $message->text,
                    $replyTo && $replyTo > 0 ? $replyTo : null,
                );

                if (($result['status'] ?? null) !== 'ok') {
                    // Окружение не готово. Дальше в этом заходе идти незачем —
                    // остальные упрутся в то же самое.
                    $stats['attempted']--;
                    break;
                }

                $this->markDelivered($message, $payload, $result['telegram_message_id'] ?? null);
                $stats['delivered']++;
            } catch (Throwable $e) {
                $this->markAttemptFailed($message, $payload, $e, $maxAttempts);
                $stats['failed']++;
            }
        }

        if ($stats['delivered'] > 0 || $stats['failed'] > 0) {
            Log::info('Досыл ответов куратора в заходе синка', $stats);
        }

        return $stats;
    }
}
