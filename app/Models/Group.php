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
    /** Статус набора (H162): forming = набирается, active = идёт обучение, archived = завершена. */
    public const STATUSES = [
        'forming' => 'Набирается',
        'active' => 'Идёт обучение',
        'archived' => 'Архив',
    ];

    protected $fillable = [
        'name', 'slug', 'intake_id', 'telegram_chat_id',
        'status', 'min_size', 'planned_start_date', 'start_date_override', 'recruitment_notified_at',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'start_date_override' => 'date',
        'recruitment_notified_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'forming',
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

    /** Группа набрана: min_size не задан (не проверяем размер) или активный состав его достиг. */
    public function isRecruited(): bool
    {
        return $this->min_size === null || $this->activeUsers()->count() >= $this->min_size;
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

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
