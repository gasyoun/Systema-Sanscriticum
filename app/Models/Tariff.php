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
    public function calculateFinalPriceForUser($user): float
    {
        if (!$user) {
            return (float) $this->price;
        }

        $finalPrice = (float) $this->price;

        // 1. АВТО-СКИДКА ДЛЯ СВОИХ (Лояльность)
        $marketing = \App\Models\MarketingSetting::first();
        if ($marketing && $marketing->is_loyalty_active) {
            // Проверяем, есть ли у юзера ЛЮБЫЕ успешные оплаты
            $hasAnyPaid = \App\Models\Payment::where('user_id', $user->id)
                ->whereIn('status', ['paid', 'success']) // Ловим оба успешных статуса
                ->exists();

            if ($hasAnyPaid) {
                // Вычитаем процент (например, 15%)
                $discount = $finalPrice * ($marketing->loyalty_discount_percent / 100);
                $finalPrice = $finalPrice - $discount;
            }
        }

        // 2. АПГРЕЙД (Вычитаем то, что уже оплачено за ЭТОТ конкретный курс)
        // ИСПРАВЛЕНИЕ ЗДЕСЬ: используем course_id напрямую вместо landing_page_id
        if ($this->course_id) {
            $alreadyPaidAmount = \App\Models\Payment::where('user_id', $user->id)
                ->where('course_id', $this->course_id)
                ->whereIn('status', ['paid', 'success'])
                ->sum('amount');
                
            $finalPrice = $finalPrice - (float) $alreadyPaidAmount;
        }

        return $finalPrice > 0 ? $finalPrice : 0;
    }
}