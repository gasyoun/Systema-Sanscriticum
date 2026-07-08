<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\InvestmentModelService;
use App\Support\RoleGate;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * «Инвест-решение» (H261, фаза E плана noboring /cases/education).
 *
 * Сценарный калькулятор крупной траты: вводим капзатраты, доп. выручку/расходы,
 * ставку и горизонт — получаем NPV, IRR, срок окупаемости, точку безубыточности
 * и вердикт-светофор, сравнивая ≥2 сценария бок о бок (как в кейсе «Юный
 * чемпион»: «с покупкой здания» vs «без»). Живые ориентиры (средний чек, CAC,
 * маржа, месячная прибыль) подтягиваются из P&L/юнит-экономики через
 * {@see InvestmentModelService::defaults()} — набивать предположения с нуля не
 * нужно.
 *
 * Считает {@see InvestmentModelService}; страница денег не двигает и ничего не
 * хранит — forward-looking планирование. Доступ — RoleGate::finance().
 */
class InvestmentModel extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 85;

    protected static ?string $navigationLabel = 'Инвест-решение';

    protected static ?string $title = 'Инвест-решение — NPV / IRR / срок окупаемости';

    protected static ?string $slug = 'investment-model';

    protected static string $view = 'filament.pages.investment-model';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public function mount(): void
    {
        $defaults = app(InvestmentModelService::class)->defaults();

        // Предзаполняем страницу воркед-примером ИЗ КЕЙСА «Юный чемпион» (два
        // сценария) — открыв страницу, оператор сразу видит рабочую модель, а
        // цифры сходятся с опубликованным кейсом (валидация видна глазами):
        //   без покупки — капзатраты 14,5 млн, аренда 160k/мес (1,92 млн/год);
        //   с покупкой  — капзатраты 19,5 млн, аренда  80k/мес (0,96 млн/год);
        //   доп. выручка одинаковая (реконструирована так, чтобы «без покупки»
        //   давал IRR ~1 % и окупаемость к 6-му году — сходимость с кейсом).
        // Горизонт 6 — как в кейсе. Ставку/порог берём из конфига.
        $this->form->fill([
            'discount_rate_pct' => $defaults['discount_rate_pct'],
            'horizon_years' => 6,

            'a_label' => 'Без покупки здания',
            'a_capex' => 14_500_000,
            'a_revenue' => 4_422_000,
            'a_expense' => 1_920_000,
            'a_growth' => 0,

            'b_label' => 'С покупкой здания',
            'b_capex' => 19_500_000,
            'b_revenue' => 4_422_000,
            'b_expense' => 960_000,
            'b_growth' => 0,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Параметры оценки')
                    ->description('Ставка дисконтирования = стоимость капитала / целевая доходность. Горизонт — на сколько лет считаем проект.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('discount_rate_pct')
                                ->label('Ставка дисконтирования, % годовых')
                                ->numeric()->minValue(0)->maxValue(100)->step(0.5)
                                ->required()->live(debounce: 500),
                            TextInput::make('horizon_years')
                                ->label('Горизонт, лет')
                                ->numeric()->minValue(1)->maxValue((int) config('investment.max_horizon_years', 30))
                                ->required()->live(debounce: 500),
                        ]),
                    ]),

                Grid::make(2)->schema([
                    $this->scenarioSection('a', 'Сценарий A'),
                    $this->scenarioSection('b', 'Сценарий B'),
                ]),
            ])
            ->statePath('data');
    }

    /** Блок ввода одного сценария (a|b). */
    private function scenarioSection(string $prefix, string $heading): Section
    {
        return Section::make($heading)
            ->schema([
                TextInput::make($prefix.'_label')
                    ->label('Название')
                    ->maxLength(120)
                    ->live(debounce: 500),
                TextInput::make($prefix.'_capex')
                    ->label('Капзатраты (разово), ₽')
                    ->helperText('Крупная трата вперёд: тираж книг, найм, офлайн-точка, запуск дорогого курса.')
                    ->numeric()->minValue(0)
                    ->live(debounce: 500),
                Grid::make(2)->schema([
                    TextInput::make($prefix.'_revenue')
                        ->label('Доп. выручка в год, ₽')
                        ->numeric()->minValue(0)
                        ->live(debounce: 500),
                    TextInput::make($prefix.'_expense')
                        ->label('Доп. расходы в год, ₽')
                        ->helperText('Аренда, ЗП, обслуживание — плоские по годам.')
                        ->numeric()->minValue(0)
                        ->live(debounce: 500),
                ]),
                TextInput::make($prefix.'_growth')
                    ->label('Рост выручки, % в год')
                    ->helperText('Ramp-up: 0 — плоская выручка.')
                    ->numeric()->step(1)
                    ->live(debounce: 500),
            ]);
    }

    /**
     * Результаты оценки обоих сценариев + сравнение + живые ориентиры для вёрстки.
     *
     * @return array<string, mixed>
     */
    public function results(): array
    {
        $d = $this->data;
        $svc = app(InvestmentModelService::class);

        $rate = ((float) ($d['discount_rate_pct'] ?? 0)) / 100.0;
        $horizon = max(1, (int) ($d['horizon_years'] ?? 1));

        $build = fn (string $p): array => $svc->evaluate([
            'label' => (string) ($d[$p.'_label'] ?? '') ?: 'Сценарий '.strtoupper($p),
            'capex' => (float) ($d[$p.'_capex'] ?? 0),
            'annual_revenue' => (float) ($d[$p.'_revenue'] ?? 0),
            'annual_expense' => (float) ($d[$p.'_expense'] ?? 0),
            'growth_pct' => (float) ($d[$p.'_growth'] ?? 0),
            'horizon_years' => $horizon,
            'discount_rate' => $rate,
        ]);

        $a = $build('a');
        $b = $build('b');

        return [
            'a' => $a,
            'b' => $b,
            'compare' => $svc->compare($a, $b['has_input'] ? $b : null),
            'defaults' => $svc->defaults(),
        ];
    }
}
