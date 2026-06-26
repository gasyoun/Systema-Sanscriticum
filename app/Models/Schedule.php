<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    /**
     * Длительность занятия по умолчанию (часы), когда `end` не задан.
     * Единый источник для isLive(), выборки расписания в кабинете и карточки.
     */
    public const DEFAULT_DURATION_HOURS = 2;

    protected $fillable = [
        'title',
        'description',
        'link',
        'start',
        'end',
        'color',
        'group_id',
        'course_id',
        'reminded_at',
        'zoom_meeting_id',
        'zoom_join_url',
        'zoom_start_url',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Перенос занятия (смена start) — снимаем отметку о напоминании,
        // чтобы classes:remind-upcoming напомнил студентам заново к новому времени.
        static::updating(function (self $schedule): void {
            if ($schedule->isDirty('start')) {
                $schedule->reminded_at = null;
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Умная отдача ссылки: сначала колонка `link`,
     * затем fallback на парсинг из description (для старых записей).
     */
    protected function link(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if (! empty($value)) {
                    return $value;
                }

                if (empty($this->attributes['description'])) {
                    return null;
                }

                // Ищем первую http(s)-ссылку в описании
                if (preg_match('~https?://[^\s<>"]+~iu', $this->attributes['description'], $matches)) {
                    return $matches[0];
                }

                return null;
            }
        );
    }

    /**
     * Признак, что событие идёт прямо сейчас (нужно для бейджа LIVE).
     */
    public function isLive(): bool
    {
        $now = now();
        $end = $this->end ?? $this->start->copy()->addHours(self::DEFAULT_DURATION_HOURS);

        return $now->between($this->start, $end);
    }

    /** Привязана ли автосозданная Zoom-встреча. */
    public function hasZoomMeeting(): bool
    {
        return ! empty($this->zoom_meeting_id);
    }

    /** Длительность в минутах (по end либо дефолту) — для создания Zoom-встречи. */
    public function durationMinutes(): int
    {
        $end = $this->end ?? $this->start->copy()->addHours(self::DEFAULT_DURATION_HOURS);

        return max(1, $this->start->diffInMinutes($end));
    }
}
