<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Считаем только студентов (не админов)
        $studentsCount = User::where('is_admin', false)->count() ?: 1;
        
        // Считаем все успешные платежи (как у тебя в модели: success или paid)
        $totalRevenue = Payment::whereIn('status', ['success', 'paid'])->sum('amount');
        
        // Считаем LTV
        $ltv = round($totalRevenue / $studentsCount);

        return [
            Stat::make('Всего студентов', $studentsCount)
                ->description('Зарегистрированных учеников')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('Общая выручка', number_format($totalRevenue, 0, '.', ' ') . ' ₽')
                ->description('Успешные оплаты')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            Stat::make('LTV Студента', number_format($ltv, 0, '.', ' ') . ' ₽')
                ->description('Средний чек на одного ученика')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->chart([7, 3, 4, 10, 15, 12, 18]), // Рисует красивый мини-график на фоне
        ];
    }
}