<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H4456 — окно доступа «студент × курс».
 *
 * Рулинг MG 09-09-2026: вечный доступ к курсам Парибка — только именное
 * исключение; остальным доступ открывается окном с датой отсечки. Пассивная
 * таблица: единственный читатель — предикат Payment::scopeWithoutExpiredAccessWindow
 * (флаг features.course_access_windows); платежи, их статусы и суммы этот
 * класс не трогает.
 *
 *  - ends_at в будущем / NULL — окно живо (NULL = вечное исключение);
 *  - ends_at в прошлом — реальные ключи курса закрыты (до покупки заново).
 */
class CourseAccessWindow extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'ends_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * Истекло ли окно на пару (user, course): строка есть, ends_at не NULL
     * и уже прошло. Нет строки — окна нет, прежнее поведение.
     */
    public static function hasExpiredFor(int $userId, int $courseId): bool
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->exists();
    }

    /**
     * Записать/обновить окно (upsert по (user, course)).
     * $endsAt = NULL — вечный доступ по именному исключению.
     */
    public static function setUntil(
        int $userId,
        int $courseId,
        ?CarbonInterface $endsAt,
        ?string $reason = null,
        ?int $by = null,
    ): self {
        return self::query()->updateOrCreate(
            ['user_id' => $userId, 'course_id' => $courseId],
            ['ends_at' => $endsAt, 'reason' => $reason, 'created_by' => $by],
        );
    }

    /** Снять окно — вернуть прежнее поведение «оплатил = владеет». */
    public static function clearFor(int $userId, int $courseId): int
    {
        return (int) self::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->delete();
    }
}
