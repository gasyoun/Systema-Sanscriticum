<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    // Разрешаем массовое заполнение этих полей
    protected $fillable = [
        'title',
        'slug',
        'description',
        'is_visible',
    ];

    // Связь: Один курс имеет много уроков
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
