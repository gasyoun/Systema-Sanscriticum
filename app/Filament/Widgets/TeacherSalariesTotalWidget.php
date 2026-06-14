<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\TeacherSalaryService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Reactive;

/**
 * Шапка ведомости «Зарплаты»: начислено за выбранный месяц, выплачено за месяц,
 * общая сумма к выплате. Месяц прокидывается со страницы (фильтр «Период») через
 * #[Reactive] $period — при смене периода виджет пересчитывается.
 */
class TeacherSalariesTotalWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /** Выбранный на странице период (YYYY-MM); приходит из TeacherSalaries::getWidgetData(). */
    #[Reactive]
    public ?string $period = null;

    protected function getStats(): array
    {
        $periodMonth = $this->period ?: now()->format('Y-m');
        $summary = app(TeacherSalaryService::class)->summaryForAll($periodMonth);

        $earnedGross = 0.0;
        $returnsPeriod = 0.0;
        $paidPeriod = 0.0;
        $balance = 0.0;
        foreach ($summary as $row) {
            $earnedGross += $row['earned_period_gross'];
            $returnsPeriod += $row['returns_period'];
            $paidPeriod += $row['paid_period'];
            $balance += max(0.0, $row['balance']);
        }

        $month = Carbon::parse($periodMonth.'-01')->translatedFormat('F Y');
        $earnedDescription = $returnsPeriod != 0.0
            ? $month.' · возвраты '.number_format($returnsPeriod, 0, '.', ' ').' ₽'
            : $month;

        return [
            Stat::make('Начислено за месяц', number_format($earnedGross, 0, '.', ' ').' ₽')
                ->description($earnedDescription)
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make('Выплачено за месяц', number_format($paidPeriod, 0, '.', ' ').' ₽')
                ->description('Зафиксированные выплаты за '.$month)
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('К выплате (всего)', number_format($balance, 0, '.', ' ').' ₽')
                ->description('Начислено всего − выплачено всего, по всем преподам')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($balance > 0 ? 'warning' : 'success'),
        ];
    }
}
