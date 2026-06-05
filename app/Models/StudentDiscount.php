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
        'type',
        'value',
        'is_active',
        'note',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Активная персональная скидка для пары (студент, курс) или null. */
    public static function activeFor(?int $userId, ?int $courseId): ?self
    {
        if (! $userId || ! $courseId) {
            return null;
        }

        return self::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
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
