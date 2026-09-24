<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ledger\LedgerInvariantViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5443 (P1, D2/D7): распределение движения по обязательству (курс, блок из
 * четырёх занятий, пробное, депозит, долг). Неизменяемо: исправление —
 * обратное распределение (reverses_allocation_id) и новое.
 *
 * @property int $id
 * @property int $movement_id
 * @property int $obligation_id
 * @property int $amount_kopecks
 */
class MoneyAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount_kopecks' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LedgerInvariantViolation('ledger: posted allocations are immutable'));
        static::deleting(fn () => throw new LedgerInvariantViolation('ledger: posted allocations are immutable'));
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(MoneyMovement::class, 'movement_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(MoneyObligation::class, 'obligation_id');
    }
}
