<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\TelegramSupportMessage;
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
 */
class PendingSupportReplyDrainer
{
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

        // Предфильтр по ОТРИЦАТЕЛЬНОМУ telegram_message_id: недоставленные несут
        // placeholder из createPendingOutgoing(), а успешная доставка заменяет
        // его настоящим, положительным. Точно и не зависит от диалекта СУБД —
        // в отличие от запроса по полю JSON.
        //
        // ЛИМИТ ТОЛЬКО ПОСЛЕ ОТСЕВА. В первой версии здесь стоял
        // `orderBy('id')->limit($batch * 4)` ДО фильтрации в PHP, и при 8670
        // исходящих окно целиком набивалось древним импортом: ждущие сообщения
        // 10978 и 15255 в него не попадали никогда, дренаж молча выбирал ноль.
        $pending = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('telegram_message_id', '<', 0)
            ->whereNotNull('raw_payload')
            ->when($accountId !== null, fn ($q) => $q->where('telegram_support_account_id', $accountId))
            ->orderBy('id')
            ->get()
            ->filter(fn (TelegramSupportMessage $m): bool => $this->isPending($m))
            ->take($batch);

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

    /**
     * Отслеживаем ли доставку этого сообщения и ждёт ли оно её.
     *
     * Критерий тот же, что у SupportObservability::delivery() и
     * SupportDeliveryStatus: наличие ключа плюс его истинность. Фильтруем в PHP,
     * а не запросом по JSON, — на SQLite (тесты) и MySQL синтаксис расходится,
     * а строк с этим ключом единицы.
     */
    private function isPending(TelegramSupportMessage $message): bool
    {
        $payload = $message->raw_payload;

        return is_array($payload)
            && array_key_exists('pending_delivery', $payload)
            && (bool) $payload['pending_delivery'];
    }

    /** @param  array<string, mixed>  $payload */
    private function markDelivered(TelegramSupportMessage $message, array $payload, mixed $telegramMessageId): void
    {
        $payload['pending_delivery'] = false;
        $payload['delivered_at'] = now()->toIso8601String();
        // Доставленное не должно таскать труп прежней ошибки.
        unset($payload['delivery_failed_at'], $payload['delivery_error']);

        $update = ['raw_payload' => $payload];
        if (! empty($telegramMessageId)) {
            $update['telegram_message_id'] = (int) $telegramMessageId;
        }

        $message->forceFill($update)->save();
    }

    /**
     * ИНВАРИАНТ: pending_delivery остаётся true. Сообщение не доставлено, и
     * SupportObservability::delivery() обязан считать его в pending ровно как
     * считал; delivery_failed_at — уточнение о ждущем, а не замена ему.
     *
     * @param  array<string, mixed>  $payload
     */
    private function markAttemptFailed(TelegramSupportMessage $message, array $payload, Throwable $e, int $maxAttempts): void
    {
        $attempts = (int) ($payload['delivery_attempts'] ?? 0) + 1;

        $payload['delivery_attempts'] = $attempts;
        $payload['delivery_error'] = mb_substr($e->getMessage(), 0, 300);

        // Пометку «не доставлено» в ленте ставим, только когда попытки кончились:
        // до этого сообщение честно ждёт следующего захода.
        if ($attempts >= $maxAttempts) {
            $payload['delivery_failed_at'] = now()->toIso8601String();
        }

        $message->forceFill(['raw_payload' => $payload])->save();

        Log::warning('Не удалось доставить ответ куратора', [
            'message_id' => $message->id,
            'chat_id' => $message->telegram_chat_id,
            'attempt' => $attempts,
            'max_attempts' => $maxAttempts,
            'error' => $e->getMessage(),
        ]);
    }
}
