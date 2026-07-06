<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\StudentUnitEconomicsService;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * «Юнит-экономика ученика»: LTV, CAC, кривая удержания, отток и число месяцев до
 * окупаемости привлечения — по когорте учеников, чья первая покупка курса
 * пришлась на выбранный период. Считается из живой БД через
 * {@see StudentUnitEconomicsService}; пороги светофора — config/unit_economics.php.
 *
 * NB: не путать с {@see UnitEconomics} («Юнит-экономика урока» — цена/доход/
 * прибыль на один урок блока). Та про урок, эта про ученика за всю его жизнь.
 *
 * Доступ — админ ИЛИ бухгалтер (RoleGate::finance): это KPI, которые
 * делегированный оператор читает сам, без владельца.
 */
class StudentUnitEconomics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationLabel = 'Юнит-экономика ученика';

    protected static ?string $title = 'Юнит-экономика ученика (LTV / CAC / удержание)';

    protected static ?string $slug = 'student-unit-economics';

    protected static string $view = 'filament.pages.student-unit-economics';

    /** Начало периода когорты (Y-m-d, wire:model.live). */
    public string $from = '';

    /** Конец периода когорты (Y-m-d, wire:model.live). */
    public string $to = '';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public function mount(): void
    {
        // По умолчанию — последний год: LTV когорты не должен быть обрезан слишком
        // узким окном, а годовое окно даёт содержательную кривую удержания.
        if ($this->from === '') {
            $this->from = now()->subMonths(12)->startOfMonth()->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $start = Carbon::parse($this->from);
        $end = Carbon::parse($this->to);
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'econ' => app(StudentUnitEconomicsService::class)->forPeriod($start, $end),
        ];
    }
}
