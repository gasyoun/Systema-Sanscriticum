<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'is_preview',
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
        'is_preview' => 'boolean',
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
        // СВОЕЙ ГРУППЫ внутри курса, а не в конец всего курса. В сплит-курсах каждый
        // урок привязан к группе, поэтому нумерация ведётся в пределах (course_id, group_id);
        // group_id = NULL (одиночные курсы / общие уроки) — отдельный бакет.
        static::creating(function (Lesson $lesson): void {
            if (empty($lesson->sort_order) && $lesson->course_id) {
                $query = static::where('course_id', $lesson->course_id);
                if ($lesson->group_id === null) {
                    $query->whereNull('group_id');
                } else {
                    $query->where('group_id', $lesson->group_id);
                }
                $lesson->sort_order = (int) $query->max('sort_order') + 1;
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function homeworkSubmissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(LectureClip::class);
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

    public function scopeFree(Builder $query): Builder
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

    /**
     * Открыт ли урок при данном наборе оплаченных тарифов (ключей payments.tariff).
     *
     * Preview-урок (is_preview) — публичный бесплатный «пример урока» продающей
     * страницы: открыт ВСЕМ, в т.ч. гостю без единого оплаченного ключа. Это
     * единственная точка правды доступа к preview — проверяется ДО ключей
     * full/block_N/block_N_hH, которые остаются неизменными для платных уроков.
     */
    public function isUnlockedBy(array $ownedKeys): bool
    {
        if ($this->is_preview) {
            return true;
        }

        return (bool) array_intersect($this->unlockingKeys(), $ownedKeys);
    }

    /** Публичные preview-уроки («Пример урока» на лендинге). */
    public function scopePreview(Builder $query): Builder
    {
        return $query->where('is_preview', true);
    }

    /**
     * Уроки, видимые студенту по членству в группах: без группы (group_id NULL —
     * общие для всех групп курса) ИЛИ привязанные к группе студента. Нужно для
     * курсов, разнесённых на 2 независимых потока.
     */
    public function scopeForUserGroups(Builder $query, $user): Builder
    {
        $groupIds = $user?->groups->pluck('id')->all() ?? [];

        return $query->where(function (Builder $q) use ($groupIds) {
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

    public function scopeShownOnMain(Builder $query): Builder
    {
        return $query->where('show_on_main', true)
            ->where('is_free', true)
            ->where('is_published', true);
    }
}
