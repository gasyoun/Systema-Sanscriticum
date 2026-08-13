<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarExerciseVersion extends Model
{
    protected $fillable = [
        'grammar_exercise_id',
        'exercise_id',
        'version_n',
        'content_hash',
        'status',
        'payload',
        'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GrammarExercise::class, 'grammar_exercise_id');
    }
}
