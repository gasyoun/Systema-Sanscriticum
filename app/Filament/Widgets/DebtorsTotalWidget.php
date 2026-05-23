<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorsReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

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
        // Тяжёлый отчёт кэшируется на 60 секунд — статистика «общий долг»
        // не обязана быть real-time. Виджет рендерится и на /admin/debtors,
        // и на дашборде (auto-discover), без кэша = двойной удар по БД.
        // Сбрасывать кэш точечно не пытаемся — TTL короткий.
        $cached = Cache::remember(
            'debtors_total_widget.stats.v1',
            now()->addSeconds(60),
            function (): array {
                $report = app(DebtorsReport::class);
                $totals = $report->totalDebtForQuery($report->query());

                return [
                    'pairs' => $totals['pairs'],
                    'by_course_count' => count($totals['by_course']),
                    'amount' => $totals['amount'],
                    'missing' => $totals['missing'],
                    'overdue_promises' => PaymentPromise::query()
                        ->where('status', PaymentPromise::STATUS_ACTIVE)
                        ->whereDate('promised_at', '<', now()->toDateString())
                        ->count(),
                    'unreliable_count' => User::query()->where('is_unreliable', true)->count(),
                ];
            }
        );

        $sum = number_format($cached['amount'], 0, '.', ' ').' ₽';
        $approxBadge = $cached['missing'] > 0 ? ' (часть «≈»)' : '';

        return [
            Stat::make('Должников (пар user×курс)', (string) $cached['pairs'])
                ->description('Курсы суммарно: '.$cached['by_course_count'])
                ->descriptionIcon('heroicon-m-users')
                ->color($cached['pairs'] > 0 ? 'warning' : 'success'),

            Stat::make('Общий долг', $sum.$approxBadge)
                ->description($cached['missing'] > 0
                    ? 'У части пар нет точного тарифа — оценено по средней цене'
                    : 'Все тарифы определены точно')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($cached['amount'] >= 50000 ? 'danger' : 'warning'),

            Stat::make('Просроченных обещаний', (string) $cached['overdue_promises'])
                ->description('Активные promises, дата уже прошла')
                ->descriptionIcon('heroicon-m-clock')
                ->color($cached['overdue_promises'] > 0 ? 'danger' : 'success'),

            Stat::make('Неблагонадёжных', (string) $cached['unreliable_count'])
                ->description('С флагом is_unreliable')
                ->descriptionIcon('heroicon-m-flag')
                ->color($cached['unreliable_count'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
