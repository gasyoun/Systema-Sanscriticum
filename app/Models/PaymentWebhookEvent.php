<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал доставок денежных вебхуков (H1359). Одна строка на уникальную
 * подписанную доставку (event_hash = sha256 сырого JWT-тела). Append-only —
 * записи не редактируются и не удаляются (паттерн PaymentAudit).
 */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    /** Вебхук применён: статус/доступ обновлены как обычно. */
    public const DECISION_APPLIED = 'applied';

    /** Такое тело события уже было — идемпотентный no-op. */
    public const DECISION_DUPLICATE = 'duplicate';

    /** Успех для платежа, который был оплачен и затем отменён/возвращён — отказ. */
    public const DECISION_REJECTED_RESURRECTION = 'rejected_resurrection';

    /** Сумма из банка расходится с payments.amount сверх допуска — отказ. */
    public const DECISION_REJECTED_AMOUNT_MISMATCH = 'rejected_amount_mismatch';

    /** Подпись валидна, но локального платежа нет (нет номера заказа / не найден). */
    public const DECISION_UNMATCHED = 'unmatched';

    /**
     * Банк прислал hold (authorized/AUTHORIZED), а не capture.
     * Только при TOCHKA_AUTHORIZED_NOT_PAID=true — доступ не выдаём (H2085).
     */
    public const DECISION_HOLD_NOT_CAPTURED = 'hold_not_captured';

    /** Решения-отказы — для отчёта операторам (AuditCheckoutIntegrity). */
    public const REJECTED_DECISIONS = [
        self::DECISION_REJECTED_RESURRECTION,
        self::DECISION_REJECTED_AMOUNT_MISMATCH,
        self::DECISION_HOLD_NOT_CAPTURED,
    ];

    /** Только момент записи; updated_at не нужен — лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'payment_id',
        'event_hash',
        'bank_status',
        'reported_amount',
        'decision',
        'created_at',
    ];

    protected $casts = [
        'reported_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** Платёж может отсутствовать (unmatched) — связь без ограничений. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
