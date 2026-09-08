<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Заявка препода на материалы курса («Мои материалы», H4325): видео-анонс,
 * бейдж 4:3, конспект — черновик, не витрина. Публикует куратор через
 * App\Services\CourseMaterialSubmissionService::publish(), см. докблок миграции.
 */
class CourseMaterialSubmission extends Model
{
    use HasFactory;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PUBLISHED = 'published';

    /** @var array<string,string> */
    public const STATUSES = [
        self::STATUS_ACCEPTED => 'Принято',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_PUBLISHED => 'Опубликовано',
    ];

    protected $fillable = [
        'course_id',
        'submitted_by_user_id',
        'status',
        'video_announce_url',
        'badge_disk',
        'badge_path',
        'badge_original_name',
        'badge_size',
        'badge_mime',
        'badge_width',
        'badge_height',
        'notes',
        'reviewed_by_user_id',
        'reviewed_at',
        'published_at',
    ];

    protected $casts = [
        'badge_size' => 'integer',
        'badge_width' => 'integer',
        'badge_height' => 'integer',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_PUBLISHED);
    }

    public function hasBadge(): bool
    {
        return filled($this->badge_path);
    }

    /** Публичная ссылка на предложенный бейдж (черновик, не витрина). */
    public function badgeUrl(): ?string
    {
        if (blank($this->badge_path)) {
            return null;
        }

        return Storage::disk($this->badge_disk)->url($this->badge_path);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
