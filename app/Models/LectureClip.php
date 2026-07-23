<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один физически вырезанный n8n/ffmpeg фрагмент лекции, загруженный в VK
 * Video/Clips (H1452, Wave 4 Anton ops-gaps). Границы `start_seconds`/
 * `end_seconds` приходят из уже существующих AI-таймкодов лекции
 * (см. ClipSpanPlanner) — эта модель только ЗАПИСЫВАЕТ результат внешней
 * нарезки, сам ffmpeg/VK-аплоад выполняет n8n.
 */
class LectureClip extends Model
{
    protected $fillable = [
        'lesson_id',
        'start_seconds',
        'end_seconds',
        'title',
        'vk_video_id',
        'vk_owner_id',
        'is_free',
        'published_at',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** Staff-marked free/public surface only (editorial ~3 per lecture). */
    public function scopeFree(Builder $query): Builder
    {
        return $query->where('is_free', true);
    }

    public function durationSeconds(): int
    {
        return max(0, $this->end_seconds - $this->start_seconds);
    }
}
