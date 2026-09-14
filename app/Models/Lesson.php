<?php

namespace App\Models;

use App\Enums\RecordingKind;
use App\Services\HomeworkAutoOpener;
use App\Services\Srs\LessonFlashCardsSync;
use App\Support\HomeworkAutoOpenScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        'recording_kind',
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
        // Занятие учебника (D7) — единственная из колонок автооткрытия, которую
        // заполняет человек. Остальные четыре ставит только автомат, и массовое
        // присваивание из формы им противопоказано.
        'textbook_lesson',
        // Разметка материала занятия для material_count-вех сертификатов:
        // auto — сканер стенограмм (LessonMaterialTagger), manual — куратор.
        'material_tag',
        'material_tag_source',
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
        'textbook_lesson' => 'integer',
        'recording_attached_at' => 'datetime',
        'homework_opens_at' => 'datetime',
        'homework_auto_opened_at' => 'datetime',
        'homework_closed_at' => 'datetime',
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

            // Точка отсчёта автооткрытия ДЗ (H1764, D2): момент, когда у урока
            // ВПЕРВЫЕ появилась запись. Ставится РОВНО ОДИН РАЗ — перезаливка
            // видео её не двигает, иначе замена битой записи уносила бы ДЗ
            // вперёд на сутки. `homework_opens_at` материализуется здесь же,
            // чтобы админ видел «когда», а не выводил это в уме.
            if ($lesson->hasVideo() && blank($lesson->recording_attached_at)) {
                $lesson->recording_attached_at = now();
                $lesson->homework_opens_at = HomeworkAutoOpener::opensAtFor($lesson->recording_attached_at);
            }
        });

        // Generic/Hindi track: open HW immediately when the recording stamp
        // is first applied. Must run after save so open()'s nested save does
        // not race the outer insert/update. Kochergina stays on the hourly
        // command (delay + 09:00); this hook only fires for generic_course_slugs.
        //
        // Important: on INSERT, Laravel does not populate wasChanged() the way
        // performUpdate does — so Zoom/n8n create-with-video never saw
        // wasChanged('recording_attached_at') and silently skipped open
        // (prod lessons 1830/1832). wasRecentlyCreated covers that path.
        static::saved(function (Lesson $lesson): void {
            $stampJustSet = $lesson->wasChanged('recording_attached_at')
                || ($lesson->wasRecentlyCreated && filled($lesson->recording_attached_at));

            if (! $stampJustSet || blank($lesson->recording_attached_at)) {
                return;
            }

            try {
                app(HomeworkAutoOpener::class)->tryOpenImmediate($lesson);
            } catch (\Throwable $e) {
                Log::warning('Lesson::saved — generic homework auto-open failed', [
                    'lesson_id' => $lesson->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // H1991 — keep the lesson-tied SrsDeck/SrsCard in sync whenever
        // flash_cards is (re)written (n8n sync, manual save, …), so the
        // one-off `srs:migrate-lesson-flash-cards` migration never goes
        // stale during the dual-read window. wasChanged() alone misses
        // INSERT (see the recording_attached_at note above) — wasRecentlyCreated
        // covers create-with-flash-cards in one step.
        static::saved(function (Lesson $lesson): void {
            $changed = $lesson->wasChanged('flash_cards')
                || ($lesson->wasRecentlyCreated && filled($lesson->flash_cards));

            if (! $changed) {
                return;
            }

            try {
                LessonFlashCardsSync::sync($lesson);
            } catch (\Throwable $e) {
                Log::warning('Lesson::saved — flash_cards SRS sync failed', [
                    'lesson_id' => $lesson->id,
                    'error' => $e->getMessage(),
                ]);
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

    public function contentCandidates(): HasMany
    {
        return $this->hasMany(ContentCandidate::class);
    }

    /** Lesson-tied SRS deck (H1991) — migrated/synced from flash_cards, see LessonFlashCardsSync. */
    public function srsDeck(): HasOne
    {
        return $this->hasOne(SrsDeck::class, 'lesson_id');
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

    /**
     * Запросный близнец hasVideo() — нужен там, где считать записи по загруженной
     * коллекции нельзя (витрина грузит уроки урезанным select без ссылок, H3100).
     * Условие обязано совпадать с hasVideo(): `filled()` отбрасывает и NULL, и
     * пустую строку, поэтому здесь тоже две проверки, а не один whereNotNull.
     */
    public function scopeWithRecording(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (['youtube_url', 'rutube_url', 'video_url'] as $column) {
                $q->orWhere(fn (Builder $w) => $w->whereNotNull($column)->where($column, '!=', ''));
            }
        });
    }

    public function attachmentsCount(): int
    {
        return is_array($this->attachments) ? count($this->attachments) : 0;
    }

    /**
     * Пути вложений как плоский список строк (Filament FileUpload хранит
     * строку или массив с path/name).
     *
     * @return list<string>
     */
    public function attachmentPaths(): array
    {
        $paths = [];
        foreach ((array) ($this->attachments ?? []) as $item) {
            $path = is_array($item) ? (string) ($item['path'] ?? $item['name'] ?? '') : (string) $item;
            $path = ltrim($path, '/');
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return list<string> */
    public function attachmentBasenames(): array
    {
        return array_values(array_map('basename', $this->attachmentPaths()));
    }

    public function attachmentPublicUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }

    public function hasTranscript(): bool
    {
        return filled($this->transcript_file);
    }

    public function hasHomeworkMaterial(): bool
    {
        return (bool) $this->homework_enabled && filled($this->homework_prompt);
    }

    /**
     * Открыт ли приём работ по этому уроку ДЛЯ ЭТОГО студента — единственная
     * точка правды (H1764). Ею пользуются и серверный гейт `HomeworkController`,
     * и витрина кабинета: две копии этого условия неизбежно разъедутся.
     *
     * = ДЗ включено И (приём не закрыт ИЛИ у студента есть точечный грант).
     *
     * Грантов в волне 1 ещё нет — ветка всегда даёт false, поэтому закрытый
     * урок закрыт для всех. Метод написан сразу в финальной форме, чтобы
     * волна 2 включала D12 внутри него, а не правила все вызывающие стороны.
     */
    public function homeworkOpenFor(?User $user): bool
    {
        if (! $this->homework_enabled) {
            return false;
        }

        if (blank($this->homework_closed_at)) {
            return true;
        }

        return $this->homeworkGrantExistsFor($user);
    }

    /** Точечный грант на закрытый приём (D12) — заводится волной 2. */
    private function homeworkGrantExistsFor(?User $user): bool
    {
        return false;
    }

    /**
     * Уроки, которые пора открыть автоматом (H1764): курс в охвате пилота,
     * занятие учебника в списке, ДЗ ещё не включено, автомат его не открывал
     * и расчётный момент открытия уже наступил.
     *
     * Пустой `course_slugs` (значение по умолчанию) даёт заведомо пустую
     * выборку — фича спит, пока курс не назван явно.
     */
    public function scopeAutoOpenCandidates(Builder $query): Builder
    {
        $textbookLessons = (array) config('homework.auto_open.textbook_lessons', []);

        if ($textbookLessons === []) {
            return $query->whereRaw('1 = 0');
        }

        // Охват считается ОДНИМ запросом по курсам и подставляется списком id
        // (H3078). Коррелированный подзапрос по `MIN(lesson_date)` тут был бы
        // хрупок: `lessons.course_id` — varchar, а `courses.id` — bigint, и
        // сравнение живёт на неявном приведении, разном в MySQL и SQLite.
        $courseIds = HomeworkAutoOpenScope::courseIdsInScope();

        if ($courseIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $allowMissing = (bool) config('homework.auto_open.open_without_textbook_lesson', true);
        $epoch = HomeworkAutoOpenScope::policyEpoch();

        return $query
            ->whereIn('course_id', $courseIds)
            ->where(fn (Builder $q) => $q
                ->whereIn('textbook_lesson', $textbookLessons)
                ->when($allowMissing, fn (Builder $qq) => $qq->orWhereNull('textbook_lesson')))
            ->where('homework_enabled', false)
            ->whereNull('homework_auto_opened_at')
            ->whereNotNull('homework_opens_at')
            // Эпоха политики: урок, чей момент открытия уже в прошлом на день
            // выкатки, не открывается никогда. Без неё инверсия охвата вывалила
            // бы 41 урок и пачку уведомлений за прошедшие недели.
            ->when($epoch !== null, fn (Builder $q) => $q->where('homework_opens_at', '>=', $epoch))
            ->where('homework_opens_at', '<=', now());
    }

    public function hasFlashcards(): bool
    {
        return is_array($this->flash_cards) && count($this->flash_cards) > 0;
    }

    /**
     * Dual-read presentation helper (H1991): prefer the migrated SRS cards
     * when a synced deck exists, otherwise fall back to the legacy JSON as
     * best-effort front/back pairs. `flash_cards` stays the source during
     * the dual-read window — this never writes.
     *
     * @return list<array{front:string, back:?string}>
     */
    public function flashcardsForDisplay(): array
    {
        $deck = $this->srsDeck;
        if ($deck !== null) {
            return $deck->cards->map(fn (SrsCard $card) => [
                'front' => (string) ($card->fields['front'] ?? ''),
                'back' => $card->fields['back'] ?? null,
            ])->all();
        }

        return array_map(
            fn ($entry) => is_array($entry)
                ? ['front' => (string) ($entry['front'] ?? reset($entry) ?: ''), 'back' => $entry['back'] ?? null]
                : ['front' => (string) $entry, 'back' => null],
            (array) $this->flash_cards,
        );
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

    /**
     * H3648 recording class. Missing column (dark deploy before migrate) = course-lesson.
     */
    public function recordingKind(): RecordingKind
    {
        if (! Schema::hasColumn($this->getTable(), 'recording_kind')) {
            return RecordingKind::CourseLesson;
        }

        return RecordingKind::tryFrom((string) ($this->recording_kind ?? ''))
            ?? RecordingKind::CourseLesson;
    }

    public function isClubStreamRecording(): bool
    {
        return $this->recordingKind()->isClubStream();
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
