<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Публикация одного слота календаря на одной площадке (ключ из
 * config/content_calendar.php). Уникальна по (slot, destination): повтор тика
 * трогает только pending-строки, published никогда не уходит второй раз.
 */
class ContentCalendarPublication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'content_calendar_slot_id',
        'destination',
        'status',
        'attempts',
        'external_id',
        'permalink',
        'error',
        'warning',
        'published_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ContentCalendarSlot::class, 'content_calendar_slot_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_PUBLISHED || $this->status === self::STATUS_FAILED;
    }
}
