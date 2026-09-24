<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\LedgerWritesDisabled;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5443 (P1, D2/D9/D12/D16): строка неизменяемого денежного журнала.
 *
 * Только вставка через {@see LedgerService}; правка и
 * удаление запрещены и здесь, и триггерами БД. Сумма — целые копейки рубля
 * со знаком: приток > 0, отток < 0, сторно = −оригинал.
 *
 * @property int $id
 * @property string $movement_key
 * @property string $type
 * @property int $amount_kopecks
 * @property int|null $root_movement_id
 * @property int|null $cap_anchor_id
 */
class MoneyMovement extends Model
{
    public const RECEIPT = 'receipt';

    public const REFUND = 'refund';

    public const REVERSAL = 'reversal';

    public const CORRECTION = 'correction';

    public const PAYOUT = 'payout';

    public const COMPENSATION = 'compensation';

    public const DIRECT_TEACHER_RECEIPT = 'direct_teacher_receipt';

    /** Приток денег студента (исходная оплата — источник предела возврата). */
    public const INFLOW_TYPES = [self::RECEIPT, self::DIRECT_TEACHER_RECEIPT];

    public const OUTFLOW_TYPES = [self::REFUND, self::PAYOUT, self::COMPENSATION];

    public const ACCOUNT_SCHOOL = 'school';

    public const ACCOUNT_TEACHER_PERSONAL = 'teacher_personal';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount_kopecks' => 'integer',
        'source_amount_minor' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn () => LedgerWritesDisabled::guard());
        static::updating(fn () => throw new LedgerInvariantViolation('ledger: posted movements are immutable'));
        static::deleting(fn () => throw new LedgerInvariantViolation('ledger: posted movements are immutable'));
    }

    public function isRoot(): bool
    {
        return $this->root_movement_id === null;
    }

    public function chainRootId(): int
    {
        return $this->root_movement_id ?? $this->id;
    }

    public function isInflowRoot(): bool
    {
        return $this->isRoot() && in_array($this->type, self::INFLOW_TYPES, true);
    }

    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_movement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MoneyAllocation::class, 'movement_id');
    }
}
