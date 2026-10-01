<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5444 (D13/D14): расчётный пакет выплаты со стабильным ключом и конечным
 * автоматом `draft → approved → paid → reversed`.
 *
 * Повторный запуск с тем же ключом возвращает ТОТ ЖЕ пакет; `approved`
 * замораживает состав, ставки и применённые блоки; `paid` требует платёжного
 * доказательства; отмена оплаченного идёт только в `reversed`. Переходы назад
 * и второй незакрытый пакет того же окна запрещены триггерами БД.
 *
 * Все суммы — копейки рубля. Валютный снимок (`payout_currency`, `fx_rate`,
 * `payout_amount_minor`) производный и появляется ПОСЛЕ авансов и зачётов.
 */
class TeacherPayoutPackage extends Model
{
    public const STATE_DRAFT = 'draft';

    public const STATE_APPROVED = 'approved';

    public const STATE_PAID = 'paid';

    public const STATE_REVERSED = 'reversed';

    protected $fillable = [
        'package_key', 'teacher_id', 'period_start', 'period_end', 'state',
        'base_kopecks', 'advance_kopecks', 'offset_kopecks', 'refund_adjustment_kopecks',
        'total_kopecks', 'payout_currency', 'fx_rate', 'fx_rate_date', 'fx_source',
        'payout_amount_minor', 'approved_at', 'approved_by', 'paid_at', 'paid_by',
        'payment_evidence_key', 'payout_movement_id', 'reversed_at', 'reversed_by',
        'reversal_reason', 'legacy_teacher_payout_id', 'meta',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'base_kopecks' => 'integer',
        'advance_kopecks' => 'integer',
        'offset_kopecks' => 'integer',
        'refund_adjustment_kopecks' => 'integer',
        'total_kopecks' => 'integer',
        'payout_amount_minor' => 'integer',
        'fx_rate_date' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
        'meta' => 'array',
    ];

    /** Стабильный ключ расчётного окна (D13): один и тот же вход — один пакет. */
    public static function keyFor(int $teacherId, string $periodStart, string $periodEnd, string $scope = 'period'): string
    {
        return sprintf('payout:%s:%d:%s:%s', $scope, $teacherId, $periodStart, $periodEnd);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TeacherPayoutPackageLine::class, 'package_id');
    }

    public function payoutMovement(): BelongsTo
    {
        return $this->belongsTo(MoneyMovement::class, 'payout_movement_id');
    }

    public function isFrozen(): bool
    {
        return $this->state !== self::STATE_DRAFT;
    }

    /** Итог в валюте выплаты, если он снят; иначе рублёвый итог в копейках. */
    public function settledMinor(): int
    {
        return $this->payout_amount_minor ?? (int) $this->total_kopecks;
    }
}
