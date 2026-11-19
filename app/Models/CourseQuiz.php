<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseQuiz extends Model
{
    protected $fillable = [
        'course_id',
        'block_number',
        'title',
        'description',
        'pass_score',
        'is_active',
    ];

    /**
     * @return array<string, int|bool|string|null>
     */
    protected function casts(): array
    {
        return [
            'block_number' => 'integer',
            'pass_score' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CourseQuizQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CourseQuizAttempt::class);
    }

    /** Лучшая попытка студента (зачёт важнее процента, при равенстве — больше баллов). */
    public function bestAttemptFor(User $user): ?CourseQuizAttempt
    {
        return $this->attempts()
            ->where('user_id', $user->id)
            ->orderByDesc('passed')
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->first();
    }

    public function isPassedBy(User $user): bool
    {
        return (bool) $this->bestAttemptFor($user)?->passed;
    }
}
