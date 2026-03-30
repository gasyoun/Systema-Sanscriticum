<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use App\Models\Payment;
use Filament\Widgets\ChartWidget;

class SalesFunnelChart extends ChartWidget
{
    protected static ?string $heading = 'Воронка продаж (Лиды -> Деньги)';
    protected static ?int $sort = 3;
    protected static ?string $maxHeight = '300px';
    
    public static function canView(): bool
    {
        return false; // Возвращаем false, и виджет полностью исчезает
    }
    
    protected function getData(): array
    {
        $leadsCount = Lead::count();
        $salesCount = Payment::whereIn('status', ['success', 'paid'])->count();

        return [
            'datasets' => [
                [
                    'label' => 'Количество',
                    'data' => [$leadsCount, $salesCount],
                    'backgroundColor' => ['#f59e0b', '#10b981'], // Желтый и Зеленый
                ],
            ],
            'labels' => ['Оставили заявку (Лиды)', 'Оплатили (Покупки)'],
        ];
    }

    protected function getType(): string
    {
        return 'bar'; 
    }
}