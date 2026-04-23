<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'block_number',
        'price',
        'old_price',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'is_active' => 'boolean',
        'block_number' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * ==========================================
     * УМНЫЙ АПГРЕЙД: Расчет цены для конкретного студента
     * ==========================================
     */
    public function getDiscountPercentForUser($user): int
    {
        if (!$user) return 0;

        $marketing = \App\Models\MarketingSetting::first();
        if (!$marketing || !$marketing->is_loyalty_active) return 0;

        // ЖЕЛЕЗОБЕТОННЫЙ ПОДСЧЕТ УНИКАЛЬНЫХ КУРСОВ (pluck + unique)
        $paidCoursesCount = \App\Models\Payment::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'success'])
            ->where('created_at', '>=', now()->subYear()) // За последний год
            ->whereNotNull('course_id') // Исключаем системные платежи без курса
            ->pluck('course_id')
            ->unique()
            ->count();

        // Проверяем пороги (от большего к меньшему)
        if ($marketing->wholesale_large_threshold > 0 && $paidCoursesCount >= $marketing->wholesale_large_threshold) {
            return $marketing->wholesale_large_discount;
        } elseif ($marketing->wholesale_small_threshold > 0 && $paidCoursesCount >= $marketing->wholesale_small_threshold) {
            return $marketing->wholesale_small_discount;
        }

        return 0; // Нет скидки
    }

    /**
     * Расчет итоговой цены (использует процент из метода выше)
     */
    public function calculateFinalPriceForUser($user): float
    {
        if (!$user) return (float) $this->price;

        $finalPrice = (float) $this->price;
        
        // 1. Получаем процент лояльности
        $discountPercent = $this->getDiscountPercentForUser($user);

        // Применяем скидку, если она есть
        if ($discountPercent > 0) {
            $finalPrice = $finalPrice - ($finalPrice * ($discountPercent / 100));
        }

        // 2. ИСПРАВЛЕННЫЙ АПГРЕЙД 
        // Вычитаем уже оплаченные деньги ТОЛЬКО если это покупка тарифа "full" (Полный курс)
        if ($this->course_id && $this->type === 'full') {
            $alreadyPaidAmount = \App\Models\Payment::where('user_id', $user->id)
                ->where('course_id', $this->course_id)
                ->whereIn('status', ['paid', 'success'])
                ->sum('amount');
                
            $finalPrice = $finalPrice - (float) $alreadyPaidAmount;
        }

        return max(0, $finalPrice);
    }
    
    /**
 * Куплен ли этот конкретный тариф пользователем.
 * Для full-тарифа — есть ли успешный платёж с tariff='full' на этом курсе.
 * Для block-тарифа — есть ли платёж с ключом 'block_{block_number}'.
 */
public function isPurchasedBy($user): bool
{
    if (!$user || !$this->course_id) {
        return false;
    }

    $tariffKey = $this->type === 'block'
        ? 'block_' . $this->block_number
        : 'full';

    return \App\Models\Payment::query()
        ->where('user_id', $user->id)
        ->where('course_id', $this->course_id)
        ->where('tariff', $tariffKey)
        ->whereIn('status', ['paid', 'success'])
        ->exists();
}
    
}