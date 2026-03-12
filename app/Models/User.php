<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// --- ДОБАВЛЯЕМ КЛАССЫ ДЛЯ ЗАЩИТЫ FILAMENT ---
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

// --- УКАЗЫВАЕМ, ЧТО ЮЗЕР ИСПОЛЬЗУЕТ ИНТЕРФЕЙС FILAMENT ---
class User extends Authenticatable implements FilamentUser
{
    // Убрал дублирующийся HasFactory
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin', // Разрешаем сохранять статус админа
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean', // База будет понимать, что это true/false
        ];
    }

    // ==========================================
    // ФЕЙСКОНТРОЛЬ В АДМИНКУ
    // ==========================================
    public function canAccessPanel(Panel $panel): bool
    {
        $safeEmail = trim(strtolower($this->email));
        return $this->is_admin || $safeEmail === 'pe4kinsmart@gmail.com';
    }

    // ==========================================
    // СВЯЗИ ДЛЯ LMS (НЕ ТРОГАЕМ, ВСЁ БЕЗОПАСНО)
    // ==========================================
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    public function completedLessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_user')
                    ->withPivot('notes')
                    ->withTimestamps();
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}