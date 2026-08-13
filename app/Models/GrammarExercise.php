<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrammarExercise extends Model
{
    protected $fillable = [
        'exercise_id',
        'topic_id',
        'risk_class',
        'status',
        'payload',
        'content_hash',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
