<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only запись выплаты по обязательству перед владельцем (H4188 п.4).
 * Реестр 05-09: «строка "Выплачено" пополняется append-only с датой и
 * ссылкой». Обновление и удаление запрещены — исправления новой записью.
 *
 * @property int $id
 * @property int $owner_draw_liability_id
 * @property numeric-string $amount
 * @property string $paid_at
 * @property string|null $reference
 */
class OwnerDrawPayment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'owner_draw_liability_id',
        'amount',
        'paid_at',
        'reference',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (OwnerDrawPayment $payment): void {
            throw new \RuntimeException('Выплату менять нельзя — реестр append-only. Исправление: новая запись.');
        });

        static::deleting(function (OwnerDrawPayment $payment): void {
            throw new \RuntimeException('Выплату удалять нельзя — реестр append-only.');
        });

        static::created(fn (OwnerDrawPayment $payment) => $payment->liability->recalcPaid());
    }

    public function liability(): BelongsTo
    {
        return $this->belongsTo(OwnerDrawLiability::class, 'owner_draw_liability_id');
    }
}
