<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\TeacherWeeklyPayoutCalendarService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * H3280 — ISO-week who-is-due grid. Live queries. RoleGate::finance().
 * Does not write teacher_payouts / payments. PayPal cover is «open the bank».
 */
class TeacherWeeklyPayoutCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Календарь выплат';

    protected static ?int $navigationSort = 46;

    protected static ?string $title = 'Календарь выплат преподавателям';

    protected static ?string $slug = 'teacher-weekly-payout-calendar';

    protected static string $view = 'filament.pages.teacher-weekly-payout-calendar';

    public int $year;

    public static function canAccess(): bool
    {
        return RoleGate::finance()
            && (bool) config('features.teacher_weekly_payout_calendar');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $grid = app(TeacherWeeklyPayoutCalendarService::class)->grid($this->year);

        return [
            'grid' => $grid,
            'attributionUrl' => PayoutAttributionGuide::getUrl(),
            'salariesUrl' => TeacherSalaries::getUrl(),
        ];
    }
}
