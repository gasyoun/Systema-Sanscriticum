<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Srs\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FSRS-состояние пары «пользователь × карточка». Одна строка на юзера и карточку.
 * См. миграцию srs_review_states (H211).
 */
class SrsReviewState extends Model
{
    protected $fillable = [
        'user_id',
        'card_id',
        'stability',
        'difficulty',
        'due',
        'reps',
        'lapses',
        'state',
        'step',
        'last_review',
    ];

    protected $casts = [
        'stability' => 'float',
        'difficulty' => 'float',
        'due' => 'datetime',
        'last_review' => 'datetime',
        'reps' => 'integer',
        'lapses' => 'integer',
        'state' => 'integer',
        'step' => 'integer',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(SrsCard::class, 'card_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function stateEnum(): State
    {
        return State::from((int) $this->state);
    }
}
