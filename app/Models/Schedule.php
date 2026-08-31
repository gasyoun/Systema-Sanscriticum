<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class Schedule extends Model
{
    use HasFactory;

    // H3790 (фаза C): распускание каникульной группы по одобрению Гасунса
    // soft-deletes будущие занятия — обратимо (withTrashed), все выборки
    // через Eloquent (фид/кабинет) отфильтровывают их автоматически.
    use SoftDeletes;

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
        'group_link_posted_at',
        'zapisi_reminded_at',
        'absent_notified_at',
        'zoom_meeting_id',
        'zoom_occurrence_uuid',
        'zoom_join_url',
        'zoom_start_url',
        'zoom_recording_url',
        'zoom_recording_received_at',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'reminded_at' => 'datetime',
        'group_link_posted_at' => 'datetime',
        'zapisi_reminded_at' => 'datetime',
        'absent_notified_at' => 'datetime',
        'zoom_recording_received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Перенос занятия (смена start) — снимаем отметку о напоминании,
        // чтобы classes:remind-upcoming напомнил студентам заново к новому времени.
        static::updating(function (self $schedule): void {
            if ($schedule->isDirty('start')) {
                // Перенос времени — напоминание, уведомление о пропуске и пост
                // ссылки в чат группы шлём заново к новому времени.
                $schedule->reminded_at = null;
                $schedule->absent_notified_at = null;
                $schedule->group_link_posted_at = null;
                $schedule->zapisi_reminded_at = null;
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

    public function attendances(): HasMany
    {
        return $this->hasMany(WebinarAttendance::class);
    }

    /** Клики по ссылке «Подключиться» (надёжная привязка студентов к занятию). */
    public function joinClicks(): HasMany
    {
        return $this->hasMany(ScheduleJoinClick::class);
    }

    /** Предварительные предупреждения студентов (H2317). */
    public function attendanceNotices(): HasMany
    {
        return $this->hasMany(ScheduleAttendanceNotice::class);
    }

    /**
     * Трекинг-ссылка «Подключиться» для конкретного студента (для бота/напоминаний,
     * где нет сессии): подписанный URL с user id, по которому JoinClassController
     * запишет клик и редиректнет на настоящий Zoom-URL. null, если ссылки занятия нет.
     */
    public function trackedJoinUrlFor(User $user, string $source = 'reminder'): ?string
    {
        if (empty($this->link)) {
            return null;
        }

        return URL::signedRoute('class.join', [
            'schedule' => $this->id,
            'u' => $user->id,
            'source' => $source,
        ]);
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
     * Найти занятие, к которому относится событие Zoom, при единой ссылке курса
     * (один meeting_id на все даты). Стратегия:
     *  1) уже привязанный запуск по occurrence uuid;
     *  2) курс по courses.zoom_meeting_id → занятие, в чьё окно попадает время
     *     (иначе ближайшее в пределах ±12ч); привязываем uuid к нему;
     *  3) legacy-фолбэк: занятие с этим meeting_id напрямую (старые ручные встречи).
     *
     * @param  string|\DateTimeInterface|null  $startTime  время запуска/участника
     */
    public static function resolveForZoomEvent(string $meetingId, ?string $occurrenceUuid, $startTime = null): ?self
    {
        if ($occurrenceUuid) {
            $bound = static::where('zoom_occurrence_uuid', $occurrenceUuid)->first();
            if ($bound) {
                return $bound;
            }
        }

        $when = $startTime ? Carbon::parse($startTime) : now();

        $course = $meetingId !== '' ? Course::where('zoom_meeting_id', $meetingId)->first() : null;
        if ($course) {
            $candidates = static::where('course_id', $course->id)->whereNotNull('start')->get();

            // Занятие, в чьё окно [start−30м; конец+30м] попадает время события.
            $match = $candidates->first(function (self $s) use ($when): bool {
                $end = $s->end ?? $s->start->copy()->addHours(self::DEFAULT_DURATION_HOURS);

                return $when->betweenIncluded($s->start->copy()->subMinutes(30), $end->copy()->addMinutes(30));
            });

            // Иначе ближайшее по времени старта в пределах ±12 часов.
            // abs(): в Carbon 3 diffInSeconds знаковый и возвращает float —
            // без модуля фильтр «±12ч» и сортировка «ближайшее» ломаются, а
            // строгий тип int (был для Carbon 2) даёт TypeError на float.
            $match ??= $candidates
                ->filter(fn (self $s): bool => abs($s->start->diffInSeconds($when)) <= 12 * 3600)
                ->sortBy(fn (self $s): float => abs($s->start->diffInSeconds($when)))
                ->first();

            if ($match) {
                if ($occurrenceUuid && ! $match->zoom_occurrence_uuid) {
                    $match->forceFill(['zoom_occurrence_uuid' => $occurrenceUuid])->save();
                }

                return $match;
            }
        }

        return $meetingId !== '' ? static::where('zoom_meeting_id', $meetingId)->first() : null;
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
