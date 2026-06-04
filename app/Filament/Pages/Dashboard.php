<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    // Скрываем «Dashboard» из меню и блокируем роут для преподавателей.
    // У преподавателя останутся доступные ресурсы (Курсы/Уроки/Расписание и т.п.),
    // на первый из них Filament и перенаправит после входа.
    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }
}
