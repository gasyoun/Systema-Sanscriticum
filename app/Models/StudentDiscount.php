<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Персональная скидка студента на конкретный курс. Применяется в
 * Tariff::calculateFinalPriceForUser ВМЕСТО накопительной скидки лояльности.
 * type='percent' — процент от цены; type='fixed' — минус сумма в рублях.
 */
class StudentDiscount extends Model
{
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'user_id',
        'course_id',
        'block_number',
        'type',
        'value',
        'is_active',
        'expires_at',
        'note',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'expires_at' => 'date',
        'block_number' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Активная персональная скидка для (студент, курс) с учётом блока.
     *
     * Приоритет: скидка, заданная именно для $blockNumber, перебивает общую.
     * Если блок-специфичной нет (или $blockNumber не задан — напр. тариф «весь
     * курс»), берётся постоянная скидка на все блоки (block_number IS NULL).
     */
    public static function activeFor(?int $userId, ?int $courseId, ?int $blockNumber = null): ?self
    {
        if (! $userId || ! $courseId) {
            return null;
        }

        $base = self::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            // H3916: срочная скидка (expires_at) мертва после даты; NULL — вечная.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()));

        // Блок-специфичная скидка перебивает общую для этого блока.
        if ($blockNumber !== null) {
            $blockSpecific = (clone $base)
                ->where('block_number', $blockNumber)
                ->latest('id')
                ->first();

            if ($blockSpecific) {
                return $blockSpecific;
            }
        }

        // Постоянная скидка на все блоки.
        return $base
            ->whereNull('block_number')
            ->latest('id')
            ->first();
    }

    /** Применяет скидку к цене (percent → −%, fixed → −value), не уходя в минус. */
    public function apply(float $price): float
    {
        $final = $this->type === self::TYPE_PERCENT
            ? $price - $price * ((float) $this->value / 100)
            : $price - (float) $this->value;

        return max(0, $final);
    }
}
