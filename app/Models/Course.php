<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    // Поля, которые можно заполнять из админки
    protected $fillable = [
        'title',
        'slug',
        'image_path',
        'description',
        'chat_url',
        'is_visible',
        'is_active',
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
    ];

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_course');
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
        return match ($this->format) {
            'live' => 'Live-поток',
            'recorded' => 'В записи',
            default => null,
        };
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
        'deposit_amount' => 'decimal:2',
        'trial_price' => 'decimal:2',
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
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

    // ==========================================
    // СВЯЗЬ: Один курс имеет много оплат
    // ==========================================
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
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
    public function upcomingSchedules(int $limit = 24): \Illuminate\Database\Eloquent\Collection
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
