<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Публикация в канале @rusamskrtam / сториз (H3930, Phase 1).
 * Текст — Bot API через магнит-бот (TelegramDeliveryChannel); медиа и
 * user-сториз персоны — Phase 2 (MadelineProto, H3964). Издатель берёт
 * только approved+due в СВОЕЙ полосе (lane).
 */
class StoryPost extends Model
{
    public const KIND_TEXT = 'text';

    public const KIND_PHOTO = 'photo';

    public const KIND_VIDEO = 'video';

    /** Канальная полоса: текстовые посты, Bot API (stories:publish-due, Phase 1). */
    public const LANE_CHANNEL = 'channel';

    /** Полоса персоны: user-сториз @rusamskrtam, MadelineProto (stories:publish-story, Phase 2). */
    public const LANE_PERSONA = 'persona';

    public const SOURCE_QUEUE = 'queue';

    public const SOURCE_HARVEST = 'harvest';

    public const SOURCE_DM = 'dm';

    public const SOURCE_HOMEWORK = 'homework';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'kind',
        'lane',
        'payload',
        'media_path',
        'source',
        'source_key',
        'status',
        'publish_at',
        'repeat_rule',
        'posted_at',
        'repeat_count',
        'telegram_message_id',
        'journal',
    ];

    protected $casts = [
        'publish_at' => 'datetime',
        'posted_at' => 'datetime',
        'repeat_rule' => 'array',
        'repeat_count' => 'integer',
    ];

    public function scopeLane(Builder $query, string $lane): Builder
    {
        return $query->where('lane', $lane);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now());
    }
}
