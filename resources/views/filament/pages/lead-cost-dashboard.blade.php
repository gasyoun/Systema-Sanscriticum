<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6">
        @livewire(\App\Filament\Pages\LeadCost\LeadCostStatsWidget::class)
        @livewire(\App\Filament\Pages\LeadCost\LeadCostChartWidget::class)
        @livewire(\App\Filament\Pages\LeadCost\DirectAdSpendsTableWidget::class)
        @livewire(\App\Filament\Pages\LeadCost\AdPostSpendsTableWidget::class)
    </div>
</x-filament-panels::page>
