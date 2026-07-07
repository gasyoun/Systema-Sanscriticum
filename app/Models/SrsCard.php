<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Карточка колоды SRS. fields — снимок значений полей. См. миграцию srs_cards (H211).
 */
class SrsCard extends Model
{
    protected $fillable = [
        'deck_id',
        'fields',
        'direction',
        'source_word_id',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(SrsDeck::class, 'deck_id');
    }

    public function sourceWord(): BelongsTo
    {
        return $this->belongsTo(DictionaryWord::class, 'source_word_id');
    }

    public function reviewStates(): HasMany
    {
        return $this->hasMany(SrsReviewState::class, 'card_id');
    }
}
