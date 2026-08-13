<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalLearningProgress extends Model
{
    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MASTERED = 'mastered';

    protected $table = 'external_learning_progress';

    protected $fillable = [
        'user_id',
        'provider',
        'object_id',
        'surface',
        'status',
        'score',
        'attempts',
        'first_started_at',
        'last_seen_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'score' => 'integer',
        'attempts' => 'integer',
        'first_started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
