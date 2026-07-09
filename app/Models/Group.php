<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str; // <--- 1. Важный импорт

class Group extends Model
{
    /** Статус набора (H162). forming — идёт набор, active — укомплектована/идёт обучение. */
    public const STATUS_FORMING = 'forming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name', 'slug', 'intake_id', 'telegram_chat_id',
        'status', 'min_size', 'planned_start_date', 'start_date_override', 'recruitment_notified_at',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'start_date_override' => 'date',
        'recruitment_notified_at' => 'datetime',
        'min_size' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_FORMING,
    ];

    /** Набор, породивший эту группу (null для исторических групп до наборов). */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
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

        // Перенос даты старта — снимаем отметку о напоминании, чтобы
        // groups:notify-forming-shortfall предупредил заново к новой дате
        // (тот же паттерн, что Schedule::booted() при переносе занятия).
        static::updating(function (self $group): void {
            if ($group->isDirty('start_date_override')) {
                $group->recruitment_notified_at = null;
            }
        });
    }

    /** Дата, от которой считаем «за N дней»: override переноса приоритетнее плана. */
    public function effectiveStartDate(): ?Carbon
    {
        $date = $this->start_date_override ?? $this->planned_start_date;

        return $date ? Carbon::parse($date) : null;
    }

    /** Набрана ли группа: порог не задан — размер не блокирует. */
    public function isUnderEnrolled(): bool
    {
        return $this->min_size !== null && $this->activeUsers()->count() < $this->min_size;
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

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
