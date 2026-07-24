<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Единая review/publish-единица n8n-контент-движка лекций (H1547, Wave 1 of 5).
 * Wave 1 создаёт только type=clip (зеркало LectureClip через
 * ContentCandidateSync); social_post/faq_draft/article/study_artifact/
 * email_blast — будущие waves 2-5, схема уже это предусматривает (delta 1,
 * ARCHITECTURE §2), чтобы не форкать таблицу пять раз.
 */
class ContentCandidate extends Model
{
    public const TYPE_CLIP = 'clip';

    public const TYPE_SOCIAL_POST = 'social_post';

    public const TYPE_FAQ_DRAFT = 'faq_draft';

    public const TYPE_ARTICLE = 'article';

    public const TYPE_STUDY_ARTIFACT = 'study_artifact';

    public const TYPE_EMAIL_BLAST = 'email_blast';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'status',
        'lesson_id',
        'lecture_clip_id',
        'parent_id',
        'title',
        'body',
        'quote',
        'channel_target',
        'meta',
        'published_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function lectureClip(): BelongsTo
    {
        return $this->belongsTo(LectureClip::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Draft or accepted — not yet published, not discarded/failed. */
    public function scopePublishable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_ACCEPTED]);
    }
}
