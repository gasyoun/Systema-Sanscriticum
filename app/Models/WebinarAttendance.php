<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Посещение вебинара участником (Zoom-вебхуки participant_joined/left, Фаза 3, #78).
 * Одна строка = одна сессия участия (key: schedule_id + zoom_participant_uuid).
 */
class WebinarAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'user_id',
        'zoom_participant_uuid',
        'name',
        'email',
        'joined_at',
        'left_at',
        'duration_seconds',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
