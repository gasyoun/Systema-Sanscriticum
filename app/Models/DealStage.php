<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Стадия воронки СДЕЛОК (GC-C1 / H1641). Отдельная от LeadStage сущность:
 * deals.stage_id — числовой id, leads.status — строковый key, формы
 * несовместимы (развилка F3 спеки §7).
 */
class DealStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'position',
        'is_won',
        'is_lost',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    /** Выигрышная стадия — цель моста от оплаты. */
    public static function won(): ?self
    {
        return static::query()->where('is_won', true)->ordered()->first();
    }

    /** Первая стадия воронки — куда возвращается сделка при реверсе платежа. */
    public static function first(): ?self
    {
        return static::query()->ordered()->first();
    }

    /** Финальная стадия — из неё нельзя молча откатиться в первую. */
    public function isFinal(): bool
    {
        return $this->is_won || $this->is_lost;
    }
}
