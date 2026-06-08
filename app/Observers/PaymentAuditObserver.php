<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\User;

/**
 * Пишет аудит финансовых операций: кто и что сделал с платежом.
 * Отдельный обсервер, чтобы не смешивать с sheet-sync (PaymentObserver).
 *
 * Действия без авторизованного пользователя (webhook Точки, импорт, CLI)
 * фиксируются как «Система» — это удобно для фильтра «только админ».
 */
class PaymentAuditObserver
{
    /**
     * Поля, изменения которых имеют финансовый смысл и попадают в лог.
     * Остальное (updated_at, deposit_consumed_at-служебка и т.п.) — шум.
     */
    private const AUDITED = [
        'user_id',
        'course_id',
        'amount',
        'tariff',
        'status',
        'start_block',
        'end_block',
        'transaction_id',
        'is_conditional',
        'deposit_consumed_at',
    ];

    public function created(Payment $payment): void
    {
        $this->record($payment, PaymentAudit::ACTION_CREATED, $this->snapshot($payment));
    }

    public function updated(Payment $payment): void
    {
        $diff = [];

        foreach (self::AUDITED as $field) {
            if (! $payment->wasChanged($field)) {
                continue;
            }
            $diff[$field] = [
                $this->scalar($payment->getOriginal($field)),
                $this->scalar($payment->getAttribute($field)),
            ];
        }

        // Поменялось только что-то незначимое (updated_at) — лог не засоряем.
        if (empty($diff)) {
            return;
        }

        $this->record($payment, PaymentAudit::ACTION_UPDATED, $diff);
    }

    public function deleted(Payment $payment): void
    {
        $this->record($payment, PaymentAudit::ACTION_DELETED, $this->snapshot($payment));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(Payment $payment, string $action, array $changes): void
    {
        $admin = auth()->user();

        PaymentAudit::create([
            'payment_id' => $payment->getKey(),
            'admin_id' => $admin instanceof User ? $admin->getKey() : null,
            'admin_name' => $admin instanceof User ? $admin->name : 'Система',
            'action' => $action,
            'amount' => $payment->amount,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * Снимок значимых полей платежа (для created/deleted).
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(Payment $payment): array
    {
        $data = [];
        foreach (self::AUDITED as $field) {
            $data[$field] = $this->scalar($payment->getAttribute($field));
        }

        return $data;
    }

    /** Приводим значение к скаляру, пригодному для JSON. */
    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
