<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarMastery extends Model
{
    public const UNSEEN = 'unseen';

    public const LEARNING = 'learning';

    public const MASTERED = 'mastered';

    protected $table = 'grammar_mastery';

    protected $fillable = [
        'user_id',
        'topic_id',
        'state',
        'correct_streak',
        'attempt_count',
        'correct_count',
        'last_attempted_at',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isMastered(): bool
    {
        return $this->state === self::MASTERED;
    }
}
