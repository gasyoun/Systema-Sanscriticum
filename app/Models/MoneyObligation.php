<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ledger\LedgerInvariantViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5443 (P1, D6/D7): обязательство перед студентом или студента.
 *
 *  - block   — блок из четырёх занятий курса; цена = договорная − скидка (D7);
 *  - trial   — пробное занятие; признаётся только после проведения (D6);
 *  - deposit — депозит: обязательство школы до зачёта, не выручка (D6);
 *  - debt    — долг, не привязанный к блоку (начальные остатки P4).
 *
 * Условия неизменяемы; меняются только write-once отметки delivered_at и
 * cancelled_at (правило дублируется триггером БД).
 *
 * @property int $id
 * @property string $kind
 * @property int $user_id
 * @property int $price_kopecks
 */
class MoneyObligation extends Model
{
    public const BLOCK = 'block';

    public const TRIAL = 'trial';

    public const DEPOSIT = 'deposit';

    public const DEBT = 'debt';

    /** Расчётная единица обычного курса — блок из четырёх занятий (D6). */
    public const LESSONS_PER_BLOCK = 4;

    /** Единственные колонки, которые можно менять после создания. */
    private const MUTABLE = ['delivered_at', 'cancelled_at', 'updated_at'];

    protected $guarded = [];

    protected $casts = [
        'list_price_kopecks' => 'integer',
        'discount_kopecks' => 'integer',
        'price_kopecks' => 'integer',
        'block_number' => 'integer',
        'lessons_count' => 'integer',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $o): void {
            $changed = array_diff(array_keys($o->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new LedgerInvariantViolation('ledger: obligation terms are immutable ('.implode(', ', $changed).')');
            }
            foreach (['delivered_at', 'cancelled_at'] as $col) {
                if ($o->isDirty($col) && $o->getOriginal($col) !== null) {
                    throw new LedgerInvariantViolation("ledger: {$col} is write-once");
                }
            }
        });
        static::deleting(fn () => throw new LedgerInvariantViolation('ledger: obligations are never deleted'));
    }

    public function isLessonObligation(): bool
    {
        return in_array($this->kind, [self::BLOCK, self::TRIAL], true);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MoneyAllocation::class, 'obligation_id');
    }
}
