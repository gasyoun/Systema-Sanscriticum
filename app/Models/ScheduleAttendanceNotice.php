<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предварительное предупреждение студента о занятии (H2317).
 * Не путать с WebinarAttendance (факт Zoom) и ScheduleJoinClick (клик по ссылке).
 */
class ScheduleAttendanceNotice extends Model
{
    public const STATUS_ABSENT = 'absent';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_LATE = 'late';

    public const STATUS_LEAVE_EARLY = 'leave_early';

    public const STATUSES = [
        self::STATUS_ABSENT,
        self::STATUS_UNCERTAIN,
        self::STATUS_LATE,
        self::STATUS_LEAVE_EARLY,
    ];

    public const SOURCE_CABINET = 'cabinet';

    public const SOURCE_TELEGRAM = 'telegram';

    public const SOURCE_VK = 'vk';

    public const SOURCE_TELEGRAM_GROUP = 'telegram_group';

    protected $fillable = [
        'user_id',
        'schedule_id',
        'status',
        'source',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public function labelRu(): string
    {
        return match ($this->status) {
            self::STATUS_ABSENT => 'Не сможет быть',
            self::STATUS_UNCERTAIN => 'Не уверен',
            self::STATUS_LATE => 'Опоздает',
            self::STATUS_LEAVE_EARLY => 'Уйдёт раньше',
            default => $this->status,
        };
    }

    public function emoji(): string
    {
        return match ($this->status) {
            self::STATUS_ABSENT => '🚫',
            self::STATUS_UNCERTAIN => '❓',
            self::STATUS_LATE => '⏰',
            self::STATUS_LEAVE_EARLY => '🚪',
            default => '📌',
        };
    }
}
