<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorsReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Сводный виджет в шапке страницы «Должники»: позволяет менеджеру одним взглядом
 * увидеть «всего долгов на N миллионов», без необходимости вручную складывать.
 * Сумма считается через DebtorsReport::totalDebtForQuery(), который пробегает
 * по ТОЙ ЖЕ выборке, что и таблица (с учётом активных фильтров было бы идеально,
 * но это потребует пропихнуть live-query из страницы — оставляем тотал по всему
 * текущему срезу должников).
 */
class DebtorsTotalWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $report = app(DebtorsReport::class);
        $totals = $report->totalDebtForQuery($report->query());

        $sum = number_format($totals['amount'], 0, '.', ' ').' ₽';
        $approxBadge = $totals['missing'] > 0 ? ' (часть «≈»)' : '';

        $overduePromises = PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', now()->toDateString())
            ->count();

        $unreliableCount = User::query()->where('is_unreliable', true)->count();

        return [
            Stat::make('Должников (пар user×курс)', (string) $totals['pairs'])
                ->description('Курсы суммарно: '.count($totals['by_course']))
                ->descriptionIcon('heroicon-m-users')
                ->color($totals['pairs'] > 0 ? 'warning' : 'success'),

            Stat::make('Общий долг', $sum.$approxBadge)
                ->description($totals['missing'] > 0
                    ? 'У части пар нет точного тарифа — оценено по средней цене'
                    : 'Все тарифы определены точно')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($totals['amount'] >= 50000 ? 'danger' : 'warning'),

            Stat::make('Просроченных обещаний', (string) $overduePromises)
                ->description('Активные promises, дата уже прошла')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overduePromises > 0 ? 'danger' : 'success'),

            Stat::make('Неблагонадёжных', (string) $unreliableCount)
                ->description('С флагом is_unreliable')
                ->descriptionIcon('heroicon-m-flag')
                ->color($unreliableCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
