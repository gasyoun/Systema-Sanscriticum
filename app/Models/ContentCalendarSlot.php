<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

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

    public const TYPE_TEACHER_SPOTLIGHT = 'teacher_spotlight';

    public const TYPE_STUDENT_STORY = 'student_story';

    /** Слот, созданный вручную в админке. */
    public const TYPE_MANUAL = 'manual';

    /** Слот из поста, вышедшего в TG-канале-источнике (ContentCalendarTelegramIngest). */
    public const TYPE_TG_CHANNEL = 'tg_channel';

    public const SOURCE_TG_CHANNEL = 'tg_channel';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_KEPT = 'kept';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELED = 'canceled';

    public const MEDIA_DISK = 'public';

    public const MEDIA_PHOTO = 'photo';

    public const MEDIA_VIDEO = 'video';

    /** Расширения, которые VK принимает как видео; всё остальное загруженное — фото. */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm'];

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
        'media_path',
        'destinations',
        'meta',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'publish_at' => 'datetime',
        'meta' => 'array',
        'destinations' => 'array',
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

    public function publications(): HasMany
    {
        return $this->hasMany(ContentCalendarPublication::class, 'content_calendar_slot_id');
    }

    /**
     * Площадки, включённые в config/content_calendar.php (непустой chat_id /
     * group_id), ключ => конфиг.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabledDestinations(): array
    {
        return array_filter(
            (array) config('content_calendar.destinations', []),
            static fn (array $d): bool => ($d['platform'] ?? null) === 'telegram'
                ? filled($d['chat_id'] ?? null)
                : (int) ($d['group_id'] ?? 0) > 0,
        );
    }

    /**
     * Куда уходит этот слот: выбранные галочками площадки ∩ включённые.
     * null в destinations = все включённые.
     *
     * @return list<string>
     */
    public function targetDestinations(): array
    {
        $enabled = array_keys(self::enabledDestinations());
        if ($this->destinations === null) {
            return $enabled;
        }

        return array_values(array_intersect($enabled, (array) $this->destinations));
    }

    /** photo|video по расширению вложения, null — пост без медиа. */
    public function mediaType(): ?string
    {
        if (blank($this->media_path)) {
            return null;
        }

        $ext = strtolower(pathinfo((string) $this->media_path, PATHINFO_EXTENSION));

        return in_array($ext, self::VIDEO_EXTENSIONS, true) ? self::MEDIA_VIDEO : self::MEDIA_PHOTO;
    }

    /** Публичный абсолютный URL вложения — его скачивает n8n перед wall.post. */
    public function mediaUrl(): ?string
    {
        return blank($this->media_path) ? null : Storage::disk(self::MEDIA_DISK)->url((string) $this->media_path);
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
