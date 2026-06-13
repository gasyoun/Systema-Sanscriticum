<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Models\AdPostSpend;
use App\Models\DirectAdSpend;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeadCostStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    /** Обновляться, когда таблицы дашборда меняют данные. */
    protected $listeners = ['leadCostUpdated' => '$refresh'];

    protected function getStats(): array
    {
        $directBudget = 0.0;
        foreach (DirectAdSpend::query()->get() as $spend) {
            $directBudget += $spend->budgetTotal();
        }

        // Лиды за всё время — по сплошному окну от первого до последнего периода,
        // чтобы пересекающиеся периоды оплаты не считались дважды.
        $minStart = DirectAdSpend::query()->min('starts_at');
        $maxEnd = DirectAdSpend::query()->max('ends_at');
        $directLeads = ($minStart && $maxEnd)
            ? Lead::countForPeriod(\Illuminate\Support\Carbon::parse($minStart), \Illuminate\Support\Carbon::parse($maxEnd))
            : 0;
        $avgCostPerLead = $directLeads > 0 ? $directBudget / $directLeads : null;

        $postBudget = (float) AdPostSpend::query()->sum('budget');
        $writers = (int) AdPostSpend::query()->sum('writers_count');
        $avgCostPerWriter = $writers > 0 ? $postBudget / $writers : null;

        return [
            Stat::make('Бюджет Директа (всего)', $this->money($directBudget))
                ->description($directLeads.' лидов за всё время')
                ->color('primary'),
            Stat::make('Средняя стоимость лида', $avgCostPerLead === null ? '—' : $this->money($avgCostPerLead))
                ->description('бюджет ÷ лиды')
                ->color($avgCostPerLead !== null && $avgCostPerLead >= 1000 ? 'danger' : 'success'),
            Stat::make('Стоимость написавшего (сред.)', $avgCostPerWriter === null ? '—' : $this->money($avgCostPerWriter))
                ->description($writers.' написавших · бюджет постов '.$this->money($postBudget))
                ->color('info'),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 0, '.', ' ').' ₽';
    }
}
