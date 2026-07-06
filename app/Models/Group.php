<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str; // <--- 1. Важный импорт

class Group extends Model
{
    protected $fillable = ['name', 'slug', 'intake_id', 'telegram_chat_id'];

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
