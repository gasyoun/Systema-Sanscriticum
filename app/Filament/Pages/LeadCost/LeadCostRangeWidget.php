<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Models\Lead;
use App\Support\LeadCostReport;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Калькулятор стоимости лида за произвольный период с разбивкой по неделям/
 * месяцам. Ничего не сохраняет — считает на лету по записям Директа,
 * раскладывая бюджет пропорционально дням (см. App\Support\LeadCostReport).
 */
class LeadCostRangeWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.lead-cost.range-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected $listeners = ['leadCostUpdated' => '$refresh'];

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth(),
            'to' => now(),
            'granularity' => 'period',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    DatePicker::make('from')
                        ->label('Период с')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->live(),
                    DatePicker::make('to')
                        ->label('по')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->live(),
                    Select::make('granularity')
                        ->label('Разбивка')
                        ->options([
                            'period' => 'Весь период',
                            'week' => 'По неделям',
                            'month' => 'По месяцам',
                        ])
                        ->default('period')
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * Корзины для выбранного диапазона. Пусто, если даты не заданы/некорректны.
     *
     * @return array<int, array{label:string, start:Carbon, end:Carbon, budget:float, leads:int, cost:?float}>
     */
    public function getBuckets(): array
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;
        if (empty($from) || empty($to)) {
            return [];
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lt($start)) {
            return [];
        }

        return LeadCostReport::buckets($start, $end, $this->data['granularity'] ?? 'period');
    }

    /**
     * Итог за весь выбранный диапазон.
     *
     * @return array{budget:float, leads:int, cost:?float}|null
     */
    public function getTotals(): ?array
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;
        if (empty($from) || empty($to)) {
            return null;
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lt($start)) {
            return null;
        }

        $budget = LeadCostReport::budgetForWindow($start, $end);
        $leads = Lead::countForPeriod($start, $end);

        return [
            'budget' => $budget,
            'leads' => $leads,
            'cost' => $leads > 0 ? $budget / $leads : null,
        ];
    }

    public function money(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, '.', ' ').' ₽';
    }
}
