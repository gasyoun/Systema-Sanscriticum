<?php

namespace App\Models;

use App\Services\ExitSurveyAutoTrigger;
use App\Support\RichHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Course extends Model
{
    use HasFactory;

    /** @var string|null previous slug to archive as alias after update */
    protected ?string $pendingSlugAlias = null;

    // Поля, которые можно заполнять из админки
    protected $fillable = [
        'title',
        'slug',
        // H3083 — «семья потоков»: ярлык, ставящий несколько курсов-потоков
        // одной программы в одну таблицу сравнения. NULL = вне семьи.
        // Заполненное значение всегда побеждает автоопределение по названию.
        'course_family',
        'image_path',
        'description',
        'chat_url',
        // Единая постоянная ссылка на Zoom-конференцию курса; meeting_id из неё
        // выводится автоматически (см. setZoomLinkAttribute) для резолва посещаемости.
        'zoom_link',
        'zoom_meeting_id',
        'is_visible',
        'is_active',
        // Курс/поток завершён, записи опубликованы. Включает «режим записей» на
        // лендинге (см. sellsRecordings()). Аддитивно, по умолчанию false.
        'is_completed',
        // H3915: момент разбора курса задачей «Exit-опрос» (дедуп авто-триггера
        // завершения). Техническое — только через forceFill.
        'exit_survey_triggered_at',
        // Живой повтор не планируется (MG H1755): куратор говорит «повтора не будет».
        'never_repeat',
        // Новизна для анонсов «только новые курсы» (MG 31-08-2026):
        // new — впервые; repeat — возвращается после года-двух;
        // no_repeat — повтора не будет; usual — обычный.
        'novelty',
        // H3807 «одна карточка на программу»: этот курс — ЗАПИСЬ вон того
        // живого курса. Курс остаётся покупаем, но своей карточки в ленте
        // каталога не получает и отдаёт rel=canonical на живой курс.
        'recording_of_course_id',
        'lessons_count',
        'hours_count',
        'teacher_id',
        'salary_type',
        'salary_value',
        // Сумма депозита («забронировать») для этого курса; null = бронь не предлагается.
        'deposit_amount',
        // Пробное занятие: цена, событие расписания (живое занятие) и служебная
        // ссылка на урок-заготовку (синхронизируется автоматически на сохранении).
        'trial_price',
        'trial_lesson_id',
        'trial_schedule_id',
        // --- НОВОЕ ПОЛЕ: Для программы лояльности ---
        'is_elective',
        'format',
        // Уровень для витрины: beginner | continuing | advanced | null (не задан).
        'level',
        // H2333: continuation shell → course that holds lessons 1…N−1 (cabinet banner).
        'predecessor_course_id',
        'continues_from_lesson',
        // --- ПРОДАЮЩАЯ СТРАНИЦА (лендинг) ---
        'audience',
        'outcomes',
        'tech_requirements',
        'meta_title',
        'meta_description',
        // Готовая фраза в вин. падеже для финального CTA (final-cta.blade.php);
        // пусто = дефолт «санскрит».
        'cta_subject',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_course');
    }

    /**
     * Tiptap `simple` HTML for `{!! !!}` sinks. Sanitized on read so rows
     * written before H2896 #8 cannot still carry script / event handlers.
     */
    public function getDescriptionHtmlAttribute(): string
    {
        return RichHtml::sanitize($this->attributes['description'] ?? null);
    }

    public function setDescriptionAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['description'] = null;

            return;
        }

        $trimmed = trim($value);
        $this->attributes['description'] = $trimmed === '' ? '' : RichHtml::sanitize($trimmed);
    }

    /**
     * При записи Zoom-ссылки автоматически вытаскиваем числовой meeting_id
     * (.../j/{id} или .../my/{name} → null). Единый источник для резолва
     * посещаемости — вебхук и Reports API приходят с этим id.
     */
    public function setZoomLinkAttribute(?string $value): void
    {
        $value = $value !== null ? trim($value) : null;
        $this->attributes['zoom_link'] = $value !== '' ? $value : null;

        if ($value && preg_match('~/j/(\d+)~', $value, $m)) {
            $this->attributes['zoom_meeting_id'] = $m[1];
        } elseif (empty($value)) {
            $this->attributes['zoom_meeting_id'] = null;
        }
    }

    // Хелпер для шаблонов — лейблы статуса
    public function isLive(): bool
    {
        return $this->format === 'live';
    }

    /**
     * Человекочитаемый лейбл формата для панели «Коротко о курсе».
     * Значения совпадают с CourseResource (Radio 'format'): live | recorded.
     */
    public function formatLabel(): ?string
    {
        // H2379: same vocabulary as catalogue card badges + hybrid cabinet.
        return match ($this->format) {
            'live' => 'Идет сейчас',
            'recorded' => 'В записи',
            default => null,
        };
    }

    /**
     * Уровни курса для витрины (H323, beginner on-ramp). Единый источник
     * значений/лейблов для админки (CourseResource), фильтра каталога
     * (CourseCatalog) и бейджей на карточке/лендинге.
     */
    public const LEVELS = [
        'beginner' => 'С нуля',
        'continuing' => 'Продолжающим',
        'advanced' => 'Продвинутый',
    ];

    /** Человекочитаемый лейбл уровня; null/неизвестное значение — null (бейдж скрыт). */
    public function levelLabel(): ?string
    {
        return self::LEVELS[$this->level] ?? null;
    }

    /**
     * Новизна курса для анонсов «только новые курсы» (MG 31-08-2026).
     * Для фильтра «новых» годятся new + repeat; no_repeat исключён намеренно.
     */
    public const NOVELTIES = [
        'new' => 'Впервые',
        'repeat' => 'Возвращается после года-двух',
        'no_repeat' => 'Повтора не будет',
        'usual' => 'Обычный',
    ];

    public function noveltyLabel(): string
    {
        return self::NOVELTIES[$this->novelty] ?? 'Обычный';
    }

    /** «Новый» для анонсов — впервые или возвращается (no_repeat/usual — нет). */
    public function isNewForAnnouncements(): bool
    {
        return in_array($this->novelty, ['new', 'repeat'], true);
    }

    /** Курсы заданного уровня (значение вне LEVELS игнорируется — фильтр не применяется). */
    public function scopeOfLevel($query, ?string $level)
    {
        if ($level === null || ! array_key_exists($level, self::LEVELS)) {
            return $query;
        }

        return $query->where('level', $level);
    }

    /**
     * H3083 — курсы одной «семьи потоков» (потоки одной программы).
     * Пустое значение фильтр не применяет: семья не задана — сравнивать нечего.
     */
    public function scopeInFamily($query, ?string $family)
    {
        $family = trim((string) $family);

        return $family === '' ? $query : $query->where('course_family', $family);
    }

    /** Курс считается новинкой, если создан за последние 30 дней. */
    public function isNew(): bool
    {
        return $this->created_at !== null
            && $this->created_at->gt(now()->subDays(30));
    }

    // Подсказываем Laravel типы данных для переключателей
    protected $casts = [
        'is_visible' => 'boolean',
        'is_elective' => 'boolean',
        'is_active' => 'boolean',
        'is_completed' => 'boolean',
        'never_repeat' => 'boolean',
        'milestones_nudge_sent_at' => 'datetime',
        'deposit_amount' => 'decimal:2',
        'trial_price' => 'decimal:2',
        'continues_from_lesson' => 'integer',
        // «Для кого» / «Чему научитесь» — массивы строк на продающей странице.
        'audience' => 'array',
        'outcomes' => 'array',
    ];

    /**
     * Course shell that holds the earlier part of the same programme (H2333).
     * Example: Hindi gr.5 (from lesson 13) → gr.3 (lessons 1–12+).
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_course_id');
    }

    /** Streams that continue this course’s archive (inverse of predecessor). */
    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'predecessor_course_id');
    }

    /** Прошлые slug'и курса (для 301 после rename). */
    public function slugAliases(): HasMany
    {
        return $this->hasMany(CourseSlugAlias::class);
    }

    /**
     * Живой курс, ЗАПИСЬЮ которого является этот (H3807, рулинг MG «одна
     * карточка на программу»). NULL = самостоятельный товар с собственной
     * карточкой.
     */
    public function recordingOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recording_of_course_id');
    }

    /** Записи этого курса, продаваемые внутри его карточки (обратная связь). */
    public function recordings(): HasMany
    {
        return $this->hasMany(self::class, 'recording_of_course_id');
    }

    /** Этот курс — запись другого, а не самостоятельный товар витрины. */
    public function isRecordingOfAnotherCourse(): bool
    {
        return $this->recording_of_course_id !== null;
    }

    /**
     * Карточка, которая представляет этот курс в магазине и в поиске. Для
     * записи — живой курс; для всех остальных — сам курс. Именно её адрес идёт
     * в `rel=canonical`, чтобы поисковик не считал две страницы одной программы
     * двумя товарами.
     */
    public function catalogCardCourse(): self
    {
        return $this->recordingOf ?? $this;
    }

    /**
     * Курсы, у которых есть собственная карточка витрины: всё, кроме записей,
     * приписанных к живому курсу. Тот же приём, что и
     * `PrivateArchiveEligibility::scopePublic` — курс остаётся жив и покупаем,
     * из ЛЕНТЫ уходит только вторая карточка одной программы.
     */
    public function scopeWithOwnCatalogCard(Builder $query): Builder
    {
        return $query->whereNull('recording_of_course_id');
    }

    /**
     * Резолв курса по каноническому slug или алиасу.
     * Единый источник для student/shop роутов и legacy-редиректов.
     */
    public static function resolveBySlug(?string $slug): ?self
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $byCanonical = static::query()->where('slug', $slug)->first();
        if ($byCanonical) {
            return $byCanonical;
        }

        $alias = CourseSlugAlias::query()->where('slug', $slug)->first();

        return $alias?->course;
    }

    public static function resolveBySlugOrFail(string $slug): self
    {
        $course = static::resolveBySlug($slug);
        if ($course === null) {
            abort(404);
        }

        return $course;
    }

    /**
     * Implicit binding `{course:slug}`: канон или alias.
     * После биндинга middleware RedirectToCanonicalCourseSlug шлёт 301, если
     * в URL был alias, а не courses.slug.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?: $this->getRouteKeyName();

        if ($field === 'slug' || $field === null) {
            return static::resolveBySlug(is_string($value) ? $value : (string) $value);
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /** Курс/поток завершён (записи опубликованы, повторного набора нет). */
    public function isCompleted(): bool
    {
        return (bool) $this->is_completed;
    }

    /**
     * Показывать ли на лендинге «режим записи» вместо «Записаться» (H266, M1).
     * Единственный источник правды для переключения CTA. Три условия, все
     * обязательны:
     *   1) фича-флаг course_recordings_sales ВКЛ (deploy-рубильник, по умолчанию OFF);
     *   2) курс помечен завершённым (is_completed);
     *   3) есть хотя бы один активный тариф-запись (is_recording).
     * Доступ/цена при этом НЕ меняются — это косметика витрины поверх обычного
     * key-based чекаута (accessKey() → 'full'/'block_N').
     */
    public function sellsRecordings(): bool
    {
        if (! config('features.course_recordings_sales', false) || ! $this->isCompleted()) {
            return false;
        }

        return $this->tariffs()
            ->where('is_active', true)
            ->where('is_recording', true)
            ->exists();
    }

    /**
     * Техтребования курса: per-course override или общий дефолт. Пусто на курсе →
     * общий текст (сейчас статичный дефолт; при появлении поля в MarketingSetting
     * читать оттуда). Единый источник для блока «Техтребования» на лендинге.
     */
    public function techRequirements(): ?string
    {
        return filled($this->tech_requirements)
            ? $this->tech_requirements
            : self::DEFAULT_TECH_REQUIREMENTS;
    }

    /** Общий дефолт техтребований (когда у курса нет override). */
    public const DEFAULT_TECH_REQUIREMENTS = 'Для обучения понадобится компьютер, планшет или смартфон с доступом в интернет и любой современный браузер. Специальное программное обеспечение не требуется — все материалы и видеозаписи доступны прямо в личном кабинете.';

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Со-преподаватели курса со своими условиями ЗП (pivot course_teacher).
     * Основной препод (teacher_id) сюда НЕ входит — он на самом курсе.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'course_teacher')
            ->withPivot(['salary_type', 'salary_value'])
            ->withTimestamps();
    }

    /**
     * Курсы, доступные преподавателю: он основной (teacher_id) ИЛИ со-препод
     * (pivot). При $teacherId === null — ничего (препод без привязки к Teacher
     * не видит курсов, как и раньше).
     */
    public function scopeForTeacher($query, ?int $teacherId)
    {
        if ($teacherId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId)
                ->orWhereHas('teachers', fn ($t) => $t->where('teachers.id', $teacherId));
        });
    }

    /** Ведёт ли курс данный преподаватель (основной или со-препод). */
    public function isTaughtBy(?int $teacherId): bool
    {
        if ($teacherId === null) {
            return false;
        }

        return (int) $this->teacher_id === $teacherId
            || $this->teachers->contains('id', $teacherId);
    }

    /**
     * Эффективные условия ЗП пары (курс, препод): основной берёт условия с
     * курса, со-препод — из pivot. null = препод не ведёт курс. Единый источник
     * правды для TeacherSalaryService и страницы расчёта.
     *
     * @return array{type: ?string, value: float}|null
     */
    public function salaryTermsFor(?int $teacherId): ?array
    {
        if ($teacherId === null) {
            return null;
        }

        if ((int) $this->teacher_id === $teacherId) {
            return ['type' => $this->salary_type, 'value' => (float) $this->salary_value];
        }

        $co = $this->teachers->firstWhere('id', $teacherId)
            ?? $this->loadMissing('teachers')->teachers->firstWhere('id', $teacherId);

        if ($co) {
            return ['type' => $co->pivot->salary_type, 'value' => (float) $co->pivot->salary_value];
        }

        return null;
    }

    /**
     * Closure-правило валидации course_id для преподавательских форм
     * (Lesson/Schedule/Certificate): отсекает чужой course_id из POST с учётом и
     * основного препода, и со-препода (pivot). Для не-преподавателей — пропускает.
     *
     * ВАЖНО: для Filament оборачивать в `->rule(fn () => Course::teacherCourseValidationRule())`.
     * Filament к голому Closure в ->rule() применяет evaluate() (резолв параметров
     * по имени) и не может разрешить $attribute — нужно внешнее замыкание, ВОЗВРАЩАЮЩЕЕ
     * это правило.
     */
    public static function teacherCourseValidationRule(): \Closure
    {
        return static function (string $attribute, $value, \Closure $fail): void {
            $user = auth()->user();
            if ($user?->isTeacher()
                && ! static::query()->forTeacher($user->teacher_id)->whereKey($value)->exists()) {
                $fail('Этот курс не закреплён за вами.');
            }
        };
    }

    // Урок-заготовка, который открывается при покупке пробного (синхронизируется
    // из trial_schedule_id на сохранении курса).
    public function trialLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'trial_lesson_id');
    }

    // Событие расписания (живое занятие), на которое попадает купивший пробное.
    public function trialSchedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'trial_schedule_id');
    }

    protected static function booted(): void
    {
        // После сохранения курса синхронизируем урок-заготовку под пробное занятие.
        static::saved(function (self $course): void {
            $course->syncTrialPlaceholderLesson();
        });

        // При смене slug старый остаётся алиасом — 301 со старых ссылок.
        static::updating(function (self $course): void {
            if ($course->isDirty('slug')) {
                $old = $course->getOriginal('slug');
                $course->pendingSlugAlias = is_string($old) && $old !== '' ? $old : null;
            }
        });

        static::updated(function (self $course): void {
            // H3915 — событие «курс завершён» (is_completed false→true): задача
            // куратору на Exit-опрос с черновиками для личной отправки. Флаг
            // features.exit_survey_auto_trigger (default OFF) решает внутри;
            // дедуп по exit_survey_triggered_at.
            if ($course->wasChanged('is_completed') && $course->is_completed) {
                app(ExitSurveyAutoTrigger::class)->handleCompleted($course);
            }

            $old = $course->pendingSlugAlias;
            $course->pendingSlugAlias = null;
            if ($old === null || $old === $course->slug) {
                return;
            }

            // Новый канон мог быть чьим-то алиасом — снимаем, чтобы resolve
            // по courses.slug однозначно выигрывал (канон всегда первый).
            CourseSlugAlias::query()
                ->where('slug', $course->slug)
                ->delete();

            // Старый slug → alias (если ещё не занят другим курсом/алиасом).
            $takenAsCanonical = self::query()
                ->where('slug', $old)
                ->where('id', '!=', $course->id)
                ->exists();
            $takenAsAlias = CourseSlugAlias::query()
                ->where('slug', $old)
                ->where('course_id', '!=', $course->id)
                ->exists();

            if ($takenAsCanonical || $takenAsAlias) {
                return;
            }

            CourseSlugAlias::query()->firstOrCreate(
                ['slug' => $old],
                ['course_id' => $course->id, 'created_at' => now()],
            );
        });
    }

    /**
     * Поддерживает урок-заготовку под выбранное событие расписания (trial_schedule_id):
     * Lesson по ключу (course_id, group_id, lesson_date) — тому же, что использует n8n
     * (LessonController::storeFromZoom), поэтому пришедшая позже запись дозальётся в неё,
     * а LessonAccessGrant купивших пробное сохранится. trial_lesson_id указывает на неё.
     */
    public function syncTrialPlaceholderLesson(): void
    {
        // Пусто — пробное не настроено: чистим служебную ссылку, заготовку не трогаем.
        if (! $this->trial_schedule_id) {
            if ($this->trial_lesson_id !== null) {
                $this->updateQuietly(['trial_lesson_id' => null]);
            }

            return;
        }

        $schedule = Schedule::find($this->trial_schedule_id);
        if (! $schedule || ! $schedule->start) {
            return;
        }

        // Ищем по тому же ключу, что и n8n. Если урок уже есть (в т.ч. с залитой
        // записью) — не перетираем его поля, только привязываем trial_lesson_id.
        $lesson = Lesson::where('course_id', $this->id)
            ->where('group_id', $schedule->group_id)
            ->whereDate('lesson_date', $schedule->start->toDateString())
            ->first();

        if (! $lesson) {
            $lesson = Lesson::create([
                'course_id' => $this->id,
                'group_id' => $schedule->group_id,
                'lesson_date' => $schedule->start->toDateString(),
                'title' => $schedule->title ?: 'Пробное занятие',
                'block_number' => $this->currentBlock()?->number ?? 1,
                'is_published' => true,
            ]);
        }

        if ($this->trial_lesson_id !== $lesson->id) {
            // Quietly — иначе saved() зациклится.
            $this->updateQuietly(['trial_lesson_id' => $lesson->id]);
        }
    }

    // Связь: Один курс имеет много уроков
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** Вопросы-ответы по курсу (блок «FAQ» продающей страницы), по порядку. */
    public function faqs(): HasMany
    {
        return $this->hasMany(CourseFaq::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Отзывы, прикреплённые к курсу из библиотеки, в порядке пивота. */
    public function testimonials(): BelongsToMany
    {
        return $this->belongsToMany(Testimonial::class, 'course_testimonial')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Публичный preview-урок курса (блок «Пример урока»). Ровно один на курс —
     * гарантируется валидацией в Filament. Фильтр is_published зеркалит публичный
     * preview-роут (ShopController::preview): блок/CTA видны ровно тогда, когда
     * урок реально открывается. hasOne берёт первый по sort_order.
     */
    public function previewLesson(): HasOne
    {
        return $this->hasOne(Lesson::class)
            ->where('is_preview', true)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Баннеры курса по форматам (16:9 / 9:16 / 4:3) + исходники PSD к ним.
     * Ровно одна строка на формат — держится unique-индексом в БД.
     * НЕ путать с image_path: там обложка витрины, у неё другой потребитель.
     */
    public function designAssets(): HasMany
    {
        return $this->hasMany(CourseDesignAsset::class);
    }

    /**
     * Сколько требуемых форматов закрыто и сколько всего требуется.
     * Читает уже загруженную связь, если она есть, — страница-матрица тянет
     * designAssets через with(), и лишний запрос на строку был бы N+1.
     *
     * @return array{done: int, total: int, missing: list<string>}
     */
    public function designReadiness(): array
    {
        /** @var list<string> $formats */
        $formats = (array) config('design_assets.formats', []);

        $present = $this->designAssets
            ->filter(fn (CourseDesignAsset $a): bool => filled($a->path))
            ->pluck('format')
            ->all();

        $missing = array_values(array_diff($formats, $present));

        return [
            'done' => count($formats) - count($missing),
            'total' => count($formats),
            'missing' => $missing,
        ];
    }

    // ==========================================
    // СВЯЗЬ: Один курс имеет много оплат
    // ==========================================
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tariffs()
    {
        return $this->hasMany(Tariff::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(CourseBlock::class)->orderBy('number');
    }

    public function certificateMilestones(): HasMany
    {
        return $this->hasMany(CertificateMilestone::class)->orderBy('start_block');
    }

    public function currentBlock(): ?CourseBlock
    {
        return $this->blocks()->current()->orderBy('number')->first();
    }

    // Связь: Курс доступен многим группам
    public function groups(): BelongsToMany
    {
        // Убедись, что модель Group существует. Если она называется иначе, поменяй здесь.
        return $this->belongsToMany(Group::class, 'course_group');
    }

    // --- НОВАЯ СВЯЗЬ: Курс -> Студенты (со статусами) ---
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('status', 'note', 'left_after_block', 'joined_at_block')
            ->withTimestamps();
    }

    /**
     * Ближайшие занятия курса для публичной страницы (блок «Расписание»).
     *
     * Зеркалит выборку из кабинета (StudentController::calendar): занятие
     * относится к курсу либо напрямую (`course_id`), либо через любую из его
     * групп (`group_id`). «Будущее» считается по `end`, а для записей без
     * `end` — от `start` минус DEFAULT_DURATION_HOURS, как в Schedule::isLive(),
     * чтобы идущее сейчас занятие не пропадало из списка ровно в момент старта.
     */
    public function upcomingSchedules(int $limit = 24): Collection
    {
        $groupIds = $this->groups()->pluck('groups.id');

        return Schedule::query()
            ->where(function ($q) use ($groupIds) {
                $q->where('course_id', $this->id);

                if ($groupIds->isNotEmpty()) {
                    $q->orWhereIn('group_id', $groupIds);
                }
            })
            ->where(function ($q) {
                $q->where('end', '>=', now())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start')
            ->limit($limit)
            ->get();
    }
}
