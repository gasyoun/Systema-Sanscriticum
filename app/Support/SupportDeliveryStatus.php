<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Состояние доставки исходящего ответа куратора в TG-support (юзербот).
 *
 * Живёт в `telegram_support_messages.raw_payload` — там же, где весь остальной
 * жизненный цикл сообщения (`pending_delivery`, `delivered_at`, `via`,
 * `reply_to_msg_id`). Отдельной колонки сознательно нет: тот же JSON читают
 * SupportObservability::delivery() и SupportParityReportBuilder, а колонка стала
 * бы вторым ответом на вопрос «дошло ли», и первый же пропущенный write развёл
 * бы ленту с дашбордом.
 *
 * ПОВОД (15-08-2026): куратор ответил студентке, увидел зелёное «отправлено»,
 * а сессия юзербота в этот момент зависла — джоб доставки умер по таймауту,
 * сообщение осталось pending навсегда, и узнать об этом было неоткуда.
 */
final class SupportDeliveryStatus
{
    public const STATE_DELIVERED = 'delivered';

    public const STATE_PENDING = 'pending';

    public const STATE_FAILED = 'failed';

    private function __construct(
        public readonly string $state,
        public readonly ?Carbon $deliveredAt,
        public readonly ?Carbon $failedAt,
        public readonly ?string $error,
        /**
         * Ждёт дольше, чем длятся законные повторы. Это ОТТЕНОК ФОРМУЛИРОВКИ,
         * а не четвёртое состояние: пометки о падении нет, доставка может ещё
         * случиться сама.
         */
        public readonly bool $isStale,
    ) {}

    /**
     * Разбор полезной нагрузки.
     *
     * Возвращает null, когда доставку этого сообщения мы не отслеживаем —
     * у импортированных из синка исходящих (куратор написал руками в самом
     * Telegram) ключа `pending_delivery` нет вовсе. Повесить на них «не
     * доставлено» значило бы завести новую ложь вместо той, что чиним.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromRawPayload(?array $payload, ?Carbon $sentAt = null): ?self
    {
        if (! is_array($payload) || ! array_key_exists('pending_delivery', $payload)) {
            return null;
        }

        // Тот же критерий, что у SupportObservability::delivery() — иначе лента
        // и дашборд начнут расходиться в ответе на один вопрос.
        $pending = (bool) $payload['pending_delivery'];

        if (! $pending) {
            return new self(
                state: self::STATE_DELIVERED,
                deliveredAt: self::time($payload['delivered_at'] ?? null),
                failedAt: null,
                error: null,
                isStale: false,
            );
        }

        $failedAt = self::time($payload['delivery_failed_at'] ?? null);

        if ($failedAt !== null) {
            return new self(
                state: self::STATE_FAILED,
                deliveredAt: null,
                failedAt: $failedAt,
                error: self::text($payload['delivery_error'] ?? null),
                isStale: false,
            );
        }

        $threshold = (int) config('services.telegram_support.pending_stale_after_minutes', 15);

        return new self(
            state: self::STATE_PENDING,
            deliveredAt: null,
            failedAt: null,
            error: null,
            isStale: $sentAt !== null && $threshold > 0 && $sentAt->lt(now()->subMinutes($threshold)),
        );
    }

    public function isDelivered(): bool
    {
        return $this->state === self::STATE_DELIVERED;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    /** Досылать имеет смысл всё, что ещё не дошло. */
    public function canRetry(): bool
    {
        return ! $this->isDelivered();
    }

    public function label(): string
    {
        return match (true) {
            $this->isDelivered() => '✓ доставлено в Telegram',
            $this->isFailed() => '⚠️ не доставлено — сообщение не ушло',
            $this->isStale => '⏳ ждёт отправки дольше обычного',
            default => '⏳ ждёт отправки в Telegram',
        };
    }

    public function cssClass(): string
    {
        return $this->state;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // Битую отметку времени игнорируем: состояние важнее её точности.
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
