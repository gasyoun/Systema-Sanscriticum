<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Маркетинговый калькулятор стоимости привлечения. Считает на лету, ничего
 * не сохраняет в БД. Доступ — админ и менеджер (маркетолог).
 */
class LeadCostCalculator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Калькулятор стоимости лида';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $title = 'Калькулятор стоимости лида';

    protected static ?string $slug = 'lead-cost-calculator';

    protected static string $view = 'filament.pages.lead-cost-calculator';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth(),
            'to' => now(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Реклама (Директ)')
                    ->description('Стоимость лида = (зарплата специалиста + пополнение кабинета) ÷ лиды за период.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('from')
                            ->label('Период с')
                            ->native(false)
                            ->live(),
                        DatePicker::make('to')
                            ->label('по')
                            ->native(false)
                            ->live(),

                        Select::make('utm_source')
                            ->label('Источник (необязательно)')
                            ->options(fn () => $this->utmSourceOptions())
                            ->searchable()
                            ->placeholder('Все источники')
                            ->live(),
                        Select::make('landing_page_id')
                            ->label('Лендинг (необязательно)')
                            ->options(fn () => LandingPage::orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->placeholder('Все лендинги')
                            ->live(),

                        TextInput::make('specialist_salary')
                            ->label('Зарплата специалиста, ₽')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true),
                        TextInput::make('ad_budget')
                            ->label('Пополнение кабинета Директа, ₽')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true),

                        Placeholder::make('budget_total')
                            ->label('Итого бюджет')
                            ->content(fn (Get $get): string => $this->money(
                                (float) $get('specialist_salary') + (float) $get('ad_budget')
                            )),
                        Placeholder::make('leads_count')
                            ->label('Лидов за период')
                            ->content(fn (Get $get): string => (string) $this->leadsCount($get)),
                        Placeholder::make('cost_per_lead')
                            ->label('Стоимость лида')
                            ->content(function (Get $get): string {
                                $leads = $this->leadsCount($get);
                                if ($leads <= 0) {
                                    return '—';
                                }
                                $budget = (float) $get('specialist_salary') + (float) $get('ad_budget');

                                return $this->money($budget / $leads);
                            }),
                    ]),

                Section::make('Рекламные посты')
                    ->description('Стоимость написавшего = бюджет за пост ÷ количество написавших.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('post_budget')
                            ->label('Бюджет за пост, ₽')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true),
                        TextInput::make('writers_count')
                            ->label('Написавших человек')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true),

                        Placeholder::make('cost_per_writer')
                            ->label('Стоимость написавшего')
                            ->content(function (Get $get): string {
                                $writers = (int) $get('writers_count');
                                if ($writers <= 0) {
                                    return '—';
                                }

                                return $this->money((float) $get('post_budget') / $writers);
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Количество лидов за выбранный период с необязательными фильтрами.
     */
    private function leadsCount(Get $get): int
    {
        $from = $get('from');
        $to = $get('to');
        if (empty($from) || empty($to)) {
            return 0;
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        return Lead::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($get('utm_source'), fn ($q, $v) => $q->where('utm_source', $v))
            ->when($get('landing_page_id'), fn ($q, $v) => $q->where('landing_page_id', $v))
            ->count();
    }

    /**
     * @return array<string, string>
     */
    private function utmSourceOptions(): array
    {
        return Lead::query()
            ->whereNotNull('utm_source')
            ->where('utm_source', '!=', '')
            ->distinct()
            ->orderBy('utm_source')
            ->pluck('utm_source', 'utm_source')
            ->all();
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ' ').' ₽';
    }
}
