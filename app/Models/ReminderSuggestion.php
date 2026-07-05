<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предложение «напомнить студенту», найденное детектором (reminders:detect-requests)
 * в переписке (веб-чат или импортированный TG-support). Сама по себе ничего не шлёт —
 * куратор подтверждает/правит/отклоняет; approve() создаёт ScheduledReminder.
 */
class ReminderSuggestion extends Model
{
    public const SOURCE_CHAT_MESSAGE = 'chat_message';

    public const SOURCE_TELEGRAM_SUPPORT_MESSAGE = 'telegram_support_message';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'detected_text',
        'suggested_date',
        'confidence',
        'status',
        'resolved_by',
        'resolved_at',
        'scheduled_reminder_id',
    ];

    protected $casts = [
        'suggested_date' => 'datetime',
        'confidence' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scheduledReminder(): BelongsTo
    {
        return $this->belongsTo(ScheduledReminder::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
