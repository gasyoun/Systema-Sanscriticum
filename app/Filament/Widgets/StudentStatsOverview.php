<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Считаем только студентов (не админов)
        $studentsCount = User::where('is_admin', false)->count() ?: 1;

        // Считаем все успешные платежи (как у тебя в модели: success или paid).
        // Исключаем выплаты ЗП преподавателям — это отток, а не выручка.
        $totalRevenue = Payment::paid()
            ->where('tariff', '!=', 'salary_payout')
            ->sum('amount');

        // Считаем LTV
        $ltv = round($totalRevenue / $studentsCount);

        // Подключили бота (TG или VK) — среди студентов (не админов).
        $botConnected = User::where('is_admin', false)
            ->where(function ($q) {
                $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id');
            })
            ->count();
        $botPercent = round($botConnected / $studentsCount * 100);

        // Оплат в месяц: считаем по завершённым календарным месяцам за последний год
        // (текущий незакрытый месяц не включаем, чтобы не занижать среднее).
        // Помесячные границы через whereBetween (как FinanceCockpitReport::monthBounds) —
        // без DATE_FORMAT, чтобы тесты на SQLite не ломались.
        $monthlyPayments = [];
        for ($i = 12; $i >= 1; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $monthlyPayments[] = Payment::paid()
                ->where('tariff', '!=', 'salary_payout')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
        }

        $monthsInRange = count($monthlyPayments);
        $avgPaymentsPerMonth = round(array_sum($monthlyPayments) / $monthsInRange, 1);

        return [
            Stat::make('Всего студентов', $studentsCount)
                ->description('Зарегистрированных учеников')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('Подключили бота', $botConnected.' из '.$studentsCount)
                ->description($botPercent.'% студентов в TG/VK')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($botPercent >= 50 ? 'success' : 'warning'),

            Stat::make('Общая выручка', number_format($totalRevenue, 0, '.', ' ').' ₽')
                ->description('Успешные оплаты')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            Stat::make('LTV Студента', number_format($ltv, 0, '.', ' ').' ₽')
                ->description('Средний чек на одного ученика')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->chart([7, 3, 4, 10, 15, 12, 18]), // Рисует красивый мини-график на фоне

            Stat::make('Оплат в месяц (среднее)', $avgPaymentsPerMonth)
                ->description('За последние '.$monthsInRange.' завершённых мес.')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary')
                ->chart($monthlyPayments),
        ];
    }
}
