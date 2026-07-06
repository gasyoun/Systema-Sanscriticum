<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use App\Models\Payment;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Widgets\ChartWidget;

class SalesFunnelChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function getHeading(): string
    {
        $conversion = self::conversionPercent();

        return "Воронка продаж (конверсия {$conversion}%)";
    }

    protected function getData(): array
    {
        $leadsCount = Lead::count();
        $salesCount = self::payingClients();

        return [
            'datasets' => [
                [
                    'label' => 'Количество',
                    'data' => [$leadsCount, $salesCount],
                    'backgroundColor' => ['#f59e0b', '#10b981'], // Желтый и Зеленый
                ],
            ],
            'labels' => ['Оставили заявку (Лиды)', 'Оплатили (Клиенты)'],
        ];
    }

    /** Уникальные плательщики — чтобы повторные покупки не завышали конверсию. */
    protected static function payingClients(): int
    {
        return Payment::paid()->distinct('user_id')->count('user_id');
    }

    /** Конверсия «лид → клиент», % с одним знаком. */
    protected static function conversionPercent(): float
    {
        $leadsCount = Lead::count();

        return $leadsCount > 0 ? round(self::payingClients() / $leadsCount * 100, 1) : 0.0;
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
