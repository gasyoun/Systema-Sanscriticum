<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Книга каталога «Книжного клуба» (H8412).
 *
 * Остаток — только это поле `stock` (решение Q6/Q9 грилла 17-11-2026).
 * `stock <= 0` значит «под заказ 2–3 недели»: заказ принимается, но витрина
 * честно предупреждает, что экземпляра на складе нет.
 */
class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'price',
        'stock',
        'weight_g',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'weight_g' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @param  Builder<Book>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BookClubOrder::class);
    }

    /** Есть ли экземпляры на складе прямо сейчас. */
    public function inStock(): bool
    {
        return $this->stock > 0;
    }

    /** Показывать ли «под заказ 2–3 недели». */
    public function isBackorder(): bool
    {
        return ! $this->inStock();
    }

    /** Цена в копейках-без-остатка: ₽ как float для расчётов заказа. */
    public function priceRub(): float
    {
        return (float) $this->price;
    }
}
