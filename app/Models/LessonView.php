<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonView extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'first_opened_at',
        'last_opened_at',
        'last_heartbeat_at',
        'open_count',
        'total_time_on_page',
        'is_completed',
    ];

    protected $casts = [
        'first_opened_at'    => 'datetime',
        'last_opened_at'     => 'datetime',
        'last_heartbeat_at'  => 'datetime',
        'open_count'         => 'integer',
        'total_time_on_page' => 'integer',
        'is_completed'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // --- Scopes ---

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }
}