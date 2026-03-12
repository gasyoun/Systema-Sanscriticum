<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    use HasFactory;

    // Поля, которые можно заполнять из админки
    protected $fillable = [
        'title',
        'slug',
        'image_path',
        'description',
        'is_visible',
        // Добавляем новые поля сюда:
        'lessons_count',
        'hours_count',
    ];

    // Связь: Один курс имеет много уроков
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
    public function tariffs()
    {
        return $this->hasMany(Tariff::class);
    }
    // Связь: Курс доступен многим группам
    public function groups(): BelongsToMany
    {
        // Убедись, что модель Group существует. Если она называется иначе, поменяй здесь.
        return $this->belongsToMany(Group::class, 'course_group');
    }
}
