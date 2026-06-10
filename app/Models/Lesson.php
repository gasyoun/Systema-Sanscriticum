<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'block_half',
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
        'block_half' => 'integer',   // NULL = урок не делится; 1/2 = половина блока
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

    // ===================================================================
    // МАТЕРИАЛЫ УРОКА — единый источник правды (для статистики/витрины).
    // PHP-аксессоры используют колонки модели; query-скоупы дублируют ту
    // же логику в SQL для фильтров/счётчиков (паритет проверяется тестом).
    // ===================================================================

    public function hasVideo(): bool
    {
        return filled($this->youtube_url) || filled($this->rutube_url) || filled($this->video_url);
    }

    public function attachmentsCount(): int
    {
        return is_array($this->attachments) ? count($this->attachments) : 0;
    }

    public function hasTranscript(): bool
    {
        return filled($this->transcript_file);
    }

    public function hasHomeworkMaterial(): bool
    {
        return (bool) $this->homework_enabled && filled($this->homework_prompt);
    }

    public function hasFlashcards(): bool
    {
        return is_array($this->flash_cards) && count($this->flash_cards) > 0;
    }

    /** Покрытие материалами = видео ИЛИ вложения ИЛИ транскрипт. ДЗ/флешкарты не учитываются. */
    public function hasCoreMaterials(): bool
    {
        return $this->hasVideo() || $this->attachmentsCount() > 0 || $this->hasTranscript();
    }

    /** Разбивка вложений по типу: ['pdf' => 2, 'audio' => 1, ...]. */
    public function attachmentTypes(): array
    {
        $map = [
            'pdf' => ['pdf'],
            'audio' => ['mp3', 'wav', 'm4a', 'ogg', 'aac'],
            'video' => ['mp4', 'mov', 'webm', 'avi', 'mkv'],
            'archive' => ['zip', 'rar', '7z'],
            'doc' => ['doc', 'docx', 'rtf', 'txt'],
        ];

        $out = [];
        foreach ((array) ($this->attachments ?? []) as $item) {
            $path = is_array($item) ? ($item['path'] ?? $item['name'] ?? '') : (string) $item;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $type = 'other';
            foreach ($map as $t => $exts) {
                if (in_array($ext, $exts, true)) {
                    $type = $t;
                    break;
                }
            }
            $out[$type] = ($out[$type] ?? 0) + 1;
        }

        return $out;
    }

    public function scopeWithVideo(Builder $q): Builder
    {
        return $q->where(function (Builder $w) {
            $w->whereRaw("COALESCE(youtube_url, '') <> ''")
                ->orWhereRaw("COALESCE(rutube_url, '') <> ''")
                ->orWhereRaw("COALESCE(video_url, '') <> ''");
        });
    }

    public function scopeWithTranscript(Builder $q): Builder
    {
        return $q->whereRaw("COALESCE(transcript_file, '') <> ''");
    }

    public function scopeWithAttachments(Builder $q): Builder
    {
        // JSON-массив непустой. Портируемо: NULL исключаем, пустой массив '[]' отбрасываем.
        return $q->whereNotNull('attachments')->whereRaw("attachments <> '[]'");
    }

    public function scopeWithFlashcards(Builder $q): Builder
    {
        return $q->whereNotNull('flash_cards')->whereRaw("flash_cards <> '[]'");
    }

    public function scopeWithHomework(Builder $q): Builder
    {
        return $q->where('homework_enabled', true)->whereRaw("COALESCE(homework_prompt, '') <> ''");
    }

    public function scopeWithCoreMaterials(Builder $q): Builder
    {
        return $q->where(function (Builder $w) {
            $w->whereRaw("COALESCE(youtube_url, '') <> ''")
                ->orWhereRaw("COALESCE(rutube_url, '') <> ''")
                ->orWhereRaw("COALESCE(video_url, '') <> ''")
                ->orWhereRaw("COALESCE(transcript_file, '') <> ''")
                ->orWhere(function (Builder $a) {
                    $a->whereNotNull('attachments')->whereRaw("attachments <> '[]'");
                });
        });
    }

    public function scopeWithoutCoreMaterials(Builder $q): Builder
    {
        return $q->whereNot(fn (Builder $w) => $w->withCoreMaterials());
    }

    public function scopeFree(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_free', true);
    }

    /**
     * Ключи тарифов, любой из которых открывает этот урок.
     *  - 'full' и весь блок ('block_N') открывают урок всегда;
     *  - если урок размечен на половину — его открывает и ключ половины ('block_N_hH').
     * Неразбитый урок (block_half=null) доступен только по 'full' / 'block_N'.
     */
    public function unlockingKeys(): array
    {
        $keys = ['full', 'block_'.$this->block_number];

        if ($this->block_half) {
            $keys[] = 'block_'.$this->block_number.'_h'.$this->block_half;
        }

        return $keys;
    }

    /** Открыт ли урок при данном наборе оплаченных тарифов (ключей payments.tariff). */
    public function isUnlockedBy(array $ownedKeys): bool
    {
        return (bool) array_intersect($this->unlockingKeys(), $ownedKeys);
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
