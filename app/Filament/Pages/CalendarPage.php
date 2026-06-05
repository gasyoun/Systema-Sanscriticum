<?php

namespace App\Filament\Pages;

use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * Страница «Календарь» — Google-Calendar-like режим расписания.
 * Рендерит ScheduleCalendarWidget (FullCalendar) с месяц/неделя/день, drag&drop
 * и созданием/редактированием событий Schedule через модалки.
 */
class CalendarPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 41;

    protected static ?string $navigationLabel = 'Календарь';

    protected static ?string $title = 'Календарь расписания';

    protected static string $view = 'filament.pages.calendar-page';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }
}
