<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Models\DirectAdSpend;
use App\Support\LeadCostReport;
use Filament\Widgets\ChartWidget;

class LeadCostChartWidget extends ChartWidget
{
    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public ?string $filter = 'month';

    protected $listeners = ['leadCostUpdated' => '$refresh'];

    public function getHeading(): ?string
    {
        return $this->filter === 'week'
            ? 'Стоимость лида по неделям'
            : 'Стоимость лида по месяцам';
    }

    protected function getFilters(): ?array
    {
        return [
            'month' => 'По месяцам',
            'week' => 'По неделям',
        ];
    }

    protected function getData(): array
    {
        $start = DirectAdSpend::query()->min('starts_at');
        $end = DirectAdSpend::query()->max('ends_at');

        if (! $start || ! $end) {
            return ['datasets' => [], 'labels' => []];
        }

        $buckets = LeadCostReport::buckets(
            \Illuminate\Support\Carbon::parse($start),
            \Illuminate\Support\Carbon::parse($end),
            $this->filter ?? 'month',
        );

        $labels = [];
        $cost = [];
        $budget = [];
        foreach ($buckets as $bucket) {
            $labels[] = $bucket['label'];
            $cost[] = $bucket['cost'] !== null ? round($bucket['cost'], 2) : null;
            $budget[] = round($bucket['budget'], 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Стоимость лида, ₽',
                    'data' => $cost,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Бюджет, ₽',
                    'data' => $budget,
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'fill' => false,
                    'tension' => 0.3,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['position' => 'left', 'title' => ['display' => true, 'text' => 'Стоимость лида, ₽']],
                'y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Бюджет, ₽']],
            ],
        ];
    }
}
