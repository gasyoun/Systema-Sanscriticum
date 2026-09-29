<?php

declare(strict_types=1);

namespace App\Services\Support\Concerns;

use App\Models\TelegramSupportMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H5065 — общий контракт «ждущего доставки» исходящего сообщения поддержки.
 *
 * Один и тот же признак читают ДВА дренажа: MadelineProto-полоса (внутри
 * `telegram-support:sync`) и полоса Telegram Business (`support:business-drain`).
 * Пока логика жила в одном из них, второй пришлось бы написать копией, и первое
 * же расхождение между копиями означало бы сообщение, которое одна полоса
 * считает доставленным, а другая — ждущим (или, хуже, отправленным дважды).
 *
 * Контракт признака ровно тот, что у SupportObservability::delivery() и
 * SupportDeliveryStatus: наличие ключа `pending_delivery` в raw_payload плюс
 * его истинность. Фильтрация — в PHP, а не запросом по JSON: на SQLite (тесты)
 * и MySQL синтаксис расходится, а строк с этим ключом единицы.
 *
 * Недоставленное несёт ОТРИЦАТЕЛЬНЫЙ telegram_message_id (placeholder из
 * SupportReplyService::createPendingOutgoing); успешная доставка заменяет его
 * настоящим, положительным. Это и есть точный предфильтр.
 */
trait TracksPendingDelivery
{
    protected function isPending(TelegramSupportMessage $message): bool
    {
        $payload = $message->raw_payload;

        return is_array($payload)
            && array_key_exists('pending_delivery', $payload)
            && (bool) $payload['pending_delivery'];
    }

    /** @param  array<string, mixed>  $payload */
    protected function markDelivered(TelegramSupportMessage $message, array $payload, mixed $telegramMessageId): void
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
    protected function markAttemptFailed(
        TelegramSupportMessage $message,
        array $payload,
        Throwable $e,
        int $maxAttempts,
    ): void {
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

    /**
     * Предфильтр ждущих: только исходящие, только отрицательный placeholder,
     * только со «своим» аккаунтом (null = все аккаунты, как у MadelineProto-полосы).
     * ЛИМИТ ТОЛЬКО ПОСЛЕ ОТСЕВА — окно до фильтрации набивалось древним
     * импортом, и ждущие в него не попадали (инцидент 15-08-2026).
     *
     * @return Collection<int, TelegramSupportMessage>
     */
    protected function pendingOutgoing(?int $accountId, int $batch): Collection
    {
        return TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('telegram_message_id', '<', 0)
            ->whereNotNull('raw_payload')
            ->when($accountId !== null, fn ($q) => $q->where('telegram_support_account_id', $accountId))
            ->orderBy('id')
            ->get()
            ->filter(fn (TelegramSupportMessage $m): bool => $this->isPending($m))
            ->take($batch);
    }
}
