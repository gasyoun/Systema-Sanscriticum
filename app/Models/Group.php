<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str; // <--- 1. Важный импорт

class Group extends Model
{
    protected $fillable = ['name', 'slug'];

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
        return $this->belongsToMany(User::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
