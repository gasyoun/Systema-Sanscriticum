<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Перк в магазине праны (spend-sink): что студент может купить за прану. Каталог
 * редактируется админом в Filament. stock=null — безлимитно.
 */
class PranaPerk extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'cost',
        'stock',
        'icon',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'cost' => 'integer',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(PranaRedemption::class);
    }

    /** Доступен ли перк к покупке (активен и не закончился). */
    public function isAvailable(): bool
    {
        return $this->is_active && ($this->stock === null || $this->stock > 0);
    }

    /** Активные перки витрины, по sort_order. */
    public function scopeShownInShop($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
