<?php

namespace App\Filament\Pages;

use App\Filament\Resources\LessonResource;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    // Пускаем на роут (иначе после логина Filament редиректит препода на /admin →
    // дашборд → 403). Самого препода уводим в mount(), дашборд он не видит.
    public static function canAccess(): bool
    {
        return true;
    }

    // Пункт «Dashboard» в меню по-прежнему скрыт от преподавателей.
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public function mount(): void
    {
        $user = auth()->user();

        // Преподавателю дашборд (продажи/выручка и т.п.) не показываем — уводим на
        // его рабочий раздел. Redirect прерывает рендер дашборд-виджетов.
        if ($user?->isTeacher() && ! $user->isAdminLike()) {
            $this->redirect(LessonResource::getUrl());
        }
    }
}
