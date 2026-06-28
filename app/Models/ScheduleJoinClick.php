<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Клик студента по ссылке «Подключиться» (кабинет/бот) — надёжная привязка
 * «студент → занятие». Даёт личность и намерение прийти; факт присутствия и
 * минуты берём из WebinarAttendance (Zoom).
 */
class ScheduleJoinClick extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'user_id',
        'source',
        'first_clicked_at',
        'last_clicked_at',
        'click_count',
    ];

    protected $casts = [
        'first_clicked_at' => 'datetime',
        'last_clicked_at' => 'datetime',
        'click_count' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Зафиксировать клик идемпотентно (одна строка на студента в занятии):
     * первый клик ставит first_clicked_at, каждый — обновляет last_clicked_at и
     * инкрементит счётчик. Источник первого клика не перетираем.
     */
    public static function record(int $scheduleId, int $userId, string $source): self
    {
        $click = static::firstOrNew([
            'schedule_id' => $scheduleId,
            'user_id' => $userId,
        ]);

        if (! $click->exists) {
            $click->source = $source;
            $click->first_clicked_at = now();
            $click->click_count = 0;
        }

        $click->last_clicked_at = now();
        $click->click_count++;
        $click->save();

        return $click;
    }
}
