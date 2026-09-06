<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str; // <--- 1. Важный импорт

class Group extends Model
{
    use HasFactory;

    /** Статус набора (H162): forming = набирается, active = идёт обучение, archived = завершена. */
    public const STATUSES = [
        'forming' => 'Набирается',
        'active' => 'Идёт обучение',
        'archived' => 'Архив',
    ];

    protected $fillable = [
        'name', 'slug', 'intake_id', 'telegram_chat_id',
        'status', 'min_size', 'planned_start_date', 'start_date_override', 'recruitment_notified_at',
        'is_on_vacation', 'vacation_resume_date',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'start_date_override' => 'date',
        'recruitment_notified_at' => 'datetime',
        'is_on_vacation' => 'boolean',
        'vacation_resume_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'forming',
    ];

    // Опрос кворума по каникулам — см. VacationQuorumPoll в этом же namespace.
    public function isOnVacationWithUnknownResume(): bool
    {
        return (bool) $this->is_on_vacation && $this->vacation_resume_date === null;
    }

    /** Набор, породивший эту группу (null для исторических групп до наборов). */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * Заявки листа, привязанные к этой группе (до и во время набора).
     * Стабильный ID группы = primary key + slug (пока status=forming).
     */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    /** Публичный стабильный идентификатор для кураторов / листов (slug или id). */
    public function publicCode(): string
    {
        return filled($this->slug) ? (string) $this->slug : (string) $this->id;
    }

    // 2. Магия автоматического заполнения
    protected static function booted()
    {
        parent::boot();

        static::creating(function ($group) {
            // Если slug пустой, создаем его из названия
            // Пример: "Группа 1" -> "gruppa-1"
            if (empty($group->slug)) {
                $group->slug = Str::slug($group->name);
            }
        });

        // Перенос плановой даты старта — снимаем отметку о рассылке недобора,
        // чтобы groups:notify-forming-shortfall предупредил заново к новой дате
        // (тот же паттерн, что Schedule::booted() для reminded_at).
        static::updating(function (self $group): void {
            if ($group->isDirty('start_date_override')) {
                $group->recruitment_notified_at = null;
            }
        });
    }

    /** Плановая дата старта с учётом ручного переноса. */
    public function effectiveStartDate(): ?Carbon
    {
        return $this->start_date_override ?? $this->planned_start_date;
    }

    /** Статус курсового участия, означающий заявленное льготное место. */
    public const STATUS_PRIVILEGED = 'Льготник';

    /** Группа набрана: min_size не задан (не проверяем размер) или порог достигнут. */
    public function isRecruited(): bool
    {
        return $this->min_size === null || $this->membersTowardMinSize() >= $this->min_size;
    }

    /**
     * Сколько участников считается в порог набора `min_size` (H3118).
     *
     * Порог — про платные места, а `activeUsers()` считает вообще всех, кто не
     * помечен вышедшим. Рулинг MG 19-08-2026: **льготников считать, полностью
     * бесплатных — нет, тут и везде.**
     *
     * Эти две категории в данных различимы. «Льготник» — заявленный статус
     * льготного места (97 человек на 19-08-2026, 95 из них с нулевой оплатой):
     * место занято осознанно, оно в порог входит. А «Записался» с нулевой
     * оплатой — необъявленный бесплатник (23 человека): такие есть и у Уши на
     * субботнем потоке, и порог они завышали.
     *
     * Считаем участника, если он льготник ИЛИ хоть раз заплатил по курсу группы
     * ненулевую сумму. Группа без курсов сравнивается по активному составу —
     * сопоставлять не с чем, а молча отдавать ноль хуже.
     */
    public function membersTowardMinSize(): int
    {
        $courseIds = $this->courses()->pluck('courses.id')->all();

        if ($courseIds === []) {
            return $this->activeUsers()->count();
        }

        return $this->activeUsers()
            ->where(function (Builder $q) use ($courseIds): void {
                $q->whereExists(function ($sub) use ($courseIds): void {
                    $sub->selectRaw('1')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->whereIn('payments.course_id', $courseIds)
                        ->where('payments.status', 'paid')
                        // «Доступ под обещание» — тоже строка paid на 0 ₽
                        // (Payment::real()); денег за ней ещё нет, места она не
                        // занимает. Заплатит — сразу попадёт в порог.
                        ->where('payments.is_conditional', false)
                        ->where('payments.amount', '>', 0);
                })->orWhereExists(function ($sub) use ($courseIds): void {
                    $sub->selectRaw('1')
                        ->from('course_user')
                        ->whereColumn('course_user.user_id', 'users.id')
                        ->whereIn('course_user.course_id', $courseIds)
                        ->where('course_user.status', self::STATUS_PRIVILEGED);
                });
            })
            ->count();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ($this->status ?? '—');
    }

    public function users(): BelongsToMany
    {
        // ВСЕ участники (включая вышедших) — путь доступа/листинга.
        return $this->belongsToMany(User::class)
            ->withPivot(['left_at', 'left_reason'])
            ->withTimestamps();
    }

    /** Активный состав группы: участники без отметки «вышел» (left_at IS NULL). */
    public function activeUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['left_at', 'left_reason'])
            ->withTimestamps()
            ->wherePivotNull('left_at');
    }

    // Курсы, к которым привязана группа (тот же pivot, что в Course::groups()).
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_group');
    }

    /**
     * Проверяющие домашек этой группы (H1729): пользователи, которым выдан грант
     * на проверку, независимо от того, ведут ли они её курсы. Связь идёт к User,
     * а не к Teacher, чтобы грант не попадал в зарплатный контур.
     */
    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_reviewer')
            ->withPivot(['can_review', 'notify', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** Занятия расписания группы (H3790): soft-deletable при роспуске. */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /** Опросы кворума «когда возобновляем?» (H3790). */
    public function vacationQuorumPolls(): HasMany
    {
        return $this->hasMany(VacationQuorumPoll::class);
    }

    /**
     * Направления группы (H1426): производная величина, не поле — набор категорий
     * всех курсов группы. Группа, чьи курсы относятся к нескольким категориям,
     * попадает под каждую (без синтетического «смешанного» бакета).
     *
     * @return Collection<int, Category>
     */
    public function directions(): Collection
    {
        return $this->courses->flatMap(fn (Course $c) => $c->categories)->unique('id')->values();
    }

    /** Группы, которые ведёт данный преподаватель (основной или со-препод, через courses()). */
    public function scopeLedBy($query, ?int $teacherId)
    {
        if ($teacherId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('courses', fn ($q) => $q->forTeacher($teacherId));
    }

    /**
     * H4253: хотя бы один преподаватель (основной или со-препод) курсов этой
     * группы в отпуске на дату $date — teacher-level окно (Teacher::isOnVacationOn),
     * отдельно от group-level {@see is_on_vacation}. Используется, чтобы
     * RemindZapisiClasses и фиды не считали занятие «скоро», пока ведущий в отпуске.
     */
    public function teachersOnVacationCovering(Carbon $date): bool
    {
        foreach ($this->courses as $course) {
            if ($course->teacher !== null && $course->teacher->isOnVacationOn($date)) {
                return true;
            }

            foreach ($course->teachers as $coTeacher) {
                if ($coTeacher->isOnVacationOn($date)) {
                    return true;
                }
            }
        }

        return false;
    }
}
