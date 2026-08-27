<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FinanceSnapshot;
use App\Services\PayoutForecastService;
use App\Services\PayrollContourService;
use App\Services\TeacherWeeklyPayoutCalendarService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * H3280 — ISO-week who-is-due grid. Live queries. RoleGate::finance().
 * Does not write teacher_payouts / payments. PayPal cover is «open the bank».
 * Расширение «весь контур» (без бухгалтера): персонал/повторяемые получатели
 * и собранность должников по преподавателям — тоже read-only.
 *
 * H3532: таб «Год» (52 ISO-недели × все получатели, формулы ×92 %) под флагом
 * TEACHER_PAYOUT_YEAR_VIEW default OFF. При OFF страница рендерится байт-в-байт
 * как прежде; единственная пишущая поверхность — finance_snapshots.
 */
class TeacherWeeklyPayoutCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Календарь выплат';

    protected static ?int $navigationSort = 46;

    protected static ?string $title = 'Календарь выплат';

    protected static ?string $slug = 'teacher-weekly-payout-calendar';

    protected static string $view = 'filament.pages.teacher-weekly-payout-calendar';

    public int $year;

    /** Активный таб: week | year (H3532). */
    public string $tab = 'week';

    /** Ручной ввод баланса PayPal, € (пишется только в finance_snapshots). */
    public ?string $paypalBalance = null;

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
        $this->tab = request()->query('tab') === 'year' && (bool) config('features.teacher_payout_year_view')
            ? 'year'
            : 'week';
    }

    public function savePaypalBalance(): void
    {
        abort_unless(RoleGate::finance() && (bool) config('features.teacher_payout_year_view'), 403);

        $validated = $this->validate([
            'paypalBalance' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        app(PayoutForecastService::class)->recordPaypalBalance((float) $validated['paypalBalance'], (int) auth()->id());
        $this->paypalBalance = null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $grid = app(TeacherWeeklyPayoutCalendarService::class)->grid($this->year);
        $contour = app(PayrollContourService::class);

        $data = [
            'grid' => $grid,
            'staff' => $contour->staffPayees(),
            'debts' => $contour->collectionReadinessByTeacher(),
            'recentPayments' => $contour->recentPaymentsByTeacher(35),
            'attributionUrl' => PayoutAttributionGuide::getUrl(),
            'salariesUrl' => TeacherSalaries::getUrl(),
            'debtorsUrl' => Debtors::getUrl(),
        ];

        if (! (bool) config('features.teacher_payout_year_view')) {
            return $data;
        }

        return $data + [
            'yearView' => true,
            'tab' => $this->tab,
            'yearGrid' => $this->tab === 'year' ? app(PayoutForecastService::class)->yearGrid($this->year) : null,
            'paypalSnapshot' => FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE),
        ];
    }
}
