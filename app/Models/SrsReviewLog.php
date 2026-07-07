<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only журнал повторений. Сырьё для будущей пере-оптимизации FSRS (Wave 5).
 * См. миграцию srs_review_logs (H211).
 */
class SrsReviewLog extends Model
{
    protected $fillable = [
        'user_id',
        'card_id',
        'deck_id',
        'rating',
        'state',
        'elapsed_ms',
        'scheduled_days',
        'stability_after',
        'difficulty_after',
        'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'state' => 'integer',
        'elapsed_ms' => 'integer',
        'scheduled_days' => 'integer',
        'stability_after' => 'float',
        'difficulty_after' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(SrsCard::class, 'card_id');
    }
}
