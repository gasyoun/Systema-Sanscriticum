<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Payments\TochkaBalanceService;
use App\Support\RoleGate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Live Tochka ClosingAvailable on «Зарплаты». Accounting/super-admin only.
 * Teachers on «Моя зарплата» never see this (canView). H3280 slice 1.
 */
class TochkaClosingAvailableWidget extends BaseWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return RoleGate::accounting()
            && (bool) config('features.tochka_balance_on_salaries');
    }

    protected function getStats(): array
    {
        $snap = app(TochkaBalanceService::class)->snapshot();
        if (! $snap['ok']) {
            return [
                Stat::make('Точка, к трате', 'нет данных')
                    ->description($snap['error'] ?? 'банк не ответил')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger'),
            ];
        }

        $stats = [
            Stat::make('Точка, к трате', number_format($snap['closing_total'], 2, '.', ' ').' ₽')
                ->description('ClosingAvailable · оба расчётных')
                ->descriptionIcon('heroicon-m-building-library')
                ->color($snap['closing_total'] > 0 ? 'success' : 'warning'),
        ];

        foreach ($snap['accounts'] as $a) {
            $stats[] = Stat::make('Счёт …'.$a['tail'], number_format($a['closing'], 2, '.', ' ').' ₽')
                ->description('блок '.number_format($a['expected'], 2, '.', ' ').' ₽')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray');
        }

        return $stats;
    }
}
