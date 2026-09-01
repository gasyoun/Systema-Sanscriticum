<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Просмотр урока: когда открыл, сколько раз, сколько времени провёл.
 *
 * ⚠ Свойство `is_completed` на ЭТОЙ модели не заполняется — оно всегда false.
 * Пройденный урок живёт в пивоте `lesson_user` ({@see User::completedLessons()}),
 * куда пишет StudentController::completeLesson(). Завершаемость считать только
 * оттуда: метрика, севшая на `lesson_views.is_completed`, вернёт ровный 0 %
 * (прод 01-09-2026: 649 строк просмотров, 0 с признаком, 166 пройденных уроков
 * в пивоте). H3764, issue #2299.
 */
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
        'last_position_seconds',
        'max_position_seconds',
        'video_duration_seconds',
    ];

    protected $casts = [
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'open_count' => 'integer',
        'total_time_on_page' => 'integer',
        'is_completed' => 'boolean',
        'last_position_seconds' => 'integer',
        'max_position_seconds' => 'integer',
        'video_duration_seconds' => 'integer',
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
