<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Настраиваемая стадия воронки лида (заменяет Lead::STATUSES/FINAL_STATUSES —
 * см. GC-C1 / H451). `is_final` — стадии, откуда нельзя молча откатить в первую.
 */
class LeadStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'sort_order',
        'is_final',
        'color',
    ];

    protected $casts = [
        'is_final' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status', 'key');
    }
}
