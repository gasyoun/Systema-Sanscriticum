<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One planned VK (or later channel) content slot (H1564, content calendar W1).
 * Body may live here for ops UX; ContentCandidate rows can attach for later waves.
 */
class ContentCalendarSlot extends Model
{
    public const CHANNEL_VK_WALL = 'vk_wall';

    public const TYPE_EMPTY = 'empty';

    public const TYPE_EVERGREEN = 'evergreen';

    public const TYPE_CLIP_TEASE = 'clip_tease';

    public const TYPE_EVENT = 'event';

    public const TYPE_SCHEDULE_NOTE = 'schedule_note';

    public const TYPE_FAQ_TEASE = 'faq_tease';

    public const TYPE_FORWARD = 'forward';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_KEPT = 'kept';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'channel',
        'slot_date',
        'slot_type',
        'status',
        'publish_at',
        'source_kind',
        'source_ref',
        'title',
        'body',
        'meta',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'publish_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $attributes = [
        'channel' => self::CHANNEL_VK_WALL,
        'slot_type' => self::TYPE_EMPTY,
        'status' => self::STATUS_EMPTY,
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(ContentCandidate::class, 'calendar_slot_id');
    }

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        return $query->whereBetween('slot_date', [$start->toDateString(), $end->toDateString()]);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /**
     * Monthly Keep → scheduled. Default publish_at = slot_date 12:00 Europe/Moscow.
     */
    public function markKept(?CarbonInterface $publishAt = null): void
    {
        $at = $publishAt ?? Carbon::parse(
            $this->slot_date->format('Y-m-d').' 12:00:00',
            'Europe/Moscow'
        );

        $this->forceFill([
            'status' => self::STATUS_SCHEDULED,
            'publish_at' => $at,
        ])->save();
    }

    public function markCanceled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELED,
        ])->save();
    }

    /** True while cancel is still allowed (D15: until publish_at − 24h). */
    public function canCancel(?CarbonInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED || $this->publish_at === null) {
            return $this->status !== self::STATUS_PUBLISHED
                && $this->status !== self::STATUS_CANCELED;
        }

        $now = $now ?? Carbon::now('Europe/Moscow');

        return $now->lt($this->publish_at->copy()->subHours(24));
    }
}
