<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseQuizAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'course_quiz_id',
        'score',
        'total',
        'passed',
        'answers',
    ];

    /**
     * @return array<string, bool|int|list<mixed>|string|null>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total' => 'integer',
            'passed' => 'boolean',
            'answers' => 'array',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'course_quiz_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
