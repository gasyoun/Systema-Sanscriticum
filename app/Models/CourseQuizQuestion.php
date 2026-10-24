<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseQuizQuestion extends Model
{
    protected $fillable = [
        'course_quiz_id',
        'question',
        'options',
        'correct_option',
        'explanation',
        'sort_order',
    ];

    /**
     * @return array<string, int|list<string>|string|null>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_option' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'course_quiz_id');
    }

    /** Текст правильного варианта — для показа разбора после отправки. */
    public function correctOptionText(): ?string
    {
        return $this->options[$this->correct_option] ?? null;
    }
}
