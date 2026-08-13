<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'topic_id',
        'exercise_id',
        'content_hash',
        'given',
        'correct',
        'attempted_at',
    ];

    protected $casts = [
        'correct' => 'boolean',
        'attempted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
