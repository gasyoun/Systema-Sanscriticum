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
        'chat_url',
        'is_visible',
        'is_active',
        'lessons_count',
        'hours_count',
        'teacher_id',
        'salary_type',
        'salary_value',
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

    // Подсказываем Laravel типы данных для переключателей
    protected $casts = [
        'is_visible' => 'boolean',
        'is_elective' => 'boolean',
        'is_active'  => 'boolean',
    ];
    
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
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
                    ->withPivot('status', 'note')
                    ->withTimestamps();
    }
}