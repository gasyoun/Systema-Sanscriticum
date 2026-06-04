<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'topic',
        'lesson_date',
        'video_url',
        'rutube_url',
        'youtube_url',
        'attachments',
        'course_id',
        'group_id',
        'is_published',
        'is_free',
        'show_on_main',
        'block_number',
        'sort_order',
        'transcript_file',
        'flash_cards',
        'duration_seconds',
        'homework_enabled',
        'homework_prompt',
        'homework_attachments',
    ];

    // Обязательно добавь это, чтобы JSON превращался в массив
    protected $casts = [
        'attachments' => 'array',
        'flash_cards' => 'array',
        'is_published' => 'boolean',
        'is_free' => 'boolean',
        'show_on_main' => 'boolean',
        'lesson_date' => 'date',
        'block_number' => 'integer', // Гарантируем, что это всегда будет число
        'sort_order' => 'integer',
        'duration_seconds' => 'integer',
        'homework_enabled' => 'boolean',
        'homework_attachments' => 'array',
    ];

    protected static function booted(): void
    {
        // Новый урок (например, из обычной формы создания) встаёт в конец списка
        // своего курса, а не на позицию 0 поверх существующих уроков.
        static::creating(function (Lesson $lesson): void {
            if (empty($lesson->sort_order) && $lesson->course_id) {
                $maxSortOrder = static::where('course_id', $lesson->course_id)->max('sort_order');
                $lesson->sort_order = (int) $maxSortOrder + 1;
            }
        });

        static::saving(function (Lesson $lesson): void {
            if ($lesson->block_number === null || $lesson->block_number === '') {
                $key = 'lesson_block_null_log:'.($lesson->id ?? 'new');

                if (Cache::add($key, 1, now()->addMinute())) {
                    Log::warning(
                        'Lesson::saving — block_number was null, fallback to 1',
                        [
                            'lesson_id' => $lesson->id,
                            'user_id' => auth()->id(),
                            'changes' => $lesson->getDirty(),
                        ]
                    );
                }

                $lesson->block_number = 1;
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function homeworkSubmissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function scopeFree(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_free', true);
    }

    /**
     * Уроки, видимые студенту по членству в группах: без группы (group_id NULL —
     * общие для всех групп курса) ИЛИ привязанные к группе студента. Нужно для
     * курсов, разнесённых на 2 независимых потока.
     */
    public function scopeForUserGroups(\Illuminate\Database\Eloquent\Builder $query, $user): \Illuminate\Database\Eloquent\Builder
    {
        $groupIds = $user?->groups->pluck('id')->all() ?? [];

        return $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($groupIds) {
            $q->whereNull('group_id');
            if (! empty($groupIds)) {
                $q->orWhereIn('group_id', $groupIds);
            }
        });
    }

    /** Доступен ли урок студенту по группе (NULL = всем; иначе — только своей группе). */
    public function isVisibleToGroupsOf($user): bool
    {
        if ($this->group_id === null) {
            return true;
        }

        return $user !== null && $user->groups->pluck('id')->contains($this->group_id);
    }

    public function scopeShownOnMain(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('show_on_main', true)
            ->where('is_free', true)
            ->where('is_published', true);
    }
}
