<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'usage_limit',
        'used_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Метод: Проверяет, можно ли применить этот код прямо сейчас
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Проверка на количество использований
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        // Проверка на срок действия
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    // Метод: Считает итоговую цену с учетом этого промокода
    public function calculateDiscountedPrice(float $originalPrice): float
    {
        if ($this->type === 'percent') {
            $discount = $originalPrice * ($this->value / 100);
            $finalPrice = $originalPrice - $discount;
        } else {
            // Если скидка фиксированная (например, 1000 руб)
            $finalPrice = $originalPrice - $this->value;
        }

        // Цена не может уйти в минус
        return $finalPrice > 0 ? $finalPrice : 0;
    }
}