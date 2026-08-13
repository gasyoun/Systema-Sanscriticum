<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrammarExerciseReview extends Model
{
    public const SAMPLED = 'sampled';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    protected $fillable = [
        'exercise_id',
        'content_hash',
        'decision',
        'note',
        'reviewer',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];
}
