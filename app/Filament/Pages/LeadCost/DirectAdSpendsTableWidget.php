<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Models\DirectAdSpend;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

class DirectAdSpendsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Директ — бюджет по периодам';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(DirectAdSpend::query())
            ->defaultSort('starts_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить период')
                    ->modalHeading('Бюджет за период')
                    ->form($this->formSchema())
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label('Период')
                    ->getStateUsing(fn (DirectAdSpend $record): string => $record->starts_at?->format('d.m.Y')
                        .' – '.$record->ends_at?->format('d.m.Y'))
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('starts_at', $direction)),
                Tables\Columns\TextColumn::make('days')
                    ->label('Дней')
                    ->getStateUsing(fn (DirectAdSpend $record): int => $record->periodDays())
                    ->alignRight(),
                Tables\Columns\TextColumn::make('specialist_salary')
                    ->label('Зарплата')
                    ->money('RUB')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('RUB')->label('Итого')),
                Tables\Columns\TextColumn::make('ad_budget')
                    ->label('Кабинет')
                    ->money('RUB')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('RUB')->label('Итого')),
                Tables\Columns\TextColumn::make('budget_total')
                    ->label('Итого бюджет')
                    ->getStateUsing(fn (DirectAdSpend $record): float => $record->budgetTotal())
                    ->money('RUB')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('leads_count')
                    ->label('Лидов')
                    ->getStateUsing(fn (DirectAdSpend $record): int => $record->leadsCount())
                    ->alignRight(),
                Tables\Columns\TextColumn::make('cost_per_lead')
                    ->label('Стоимость лида')
                    ->badge()
                    ->getStateUsing(fn (DirectAdSpend $record): string => $record->costPerLead() === null
                        ? '—'
                        : self::money($record->costPerLead()))
                    ->color(fn (DirectAdSpend $record): string => match (true) {
                        $record->costPerLead() === null => 'gray',
                        $record->costPerLead() >= 1000 => 'danger',
                        $record->costPerLead() >= 500 => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('utm_source')
                    ->label('Источник')
                    ->placeholder('все')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_locked')
                    ->label('Замок')
                    ->boolean()
                    ->trueIcon('heroicon-s-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (DirectAdSpend $record): string => $record->is_locked
                        ? 'Заблокировано от правки/удаления'
                        : 'Открыто для правки')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form($this->formSchema())
                    // Заблокированную (is_locked) историю правит только админ.
                    ->visible(fn (DirectAdSpend $record): bool => ! $record->is_locked || RoleGate::adminOnly())
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
                Tables\Actions\DeleteAction::make()
                    // Удаление истории расходов — только админ и только если запись
                    // не заблокирована (soft-delete: физически не исчезает).
                    ->visible(fn (DirectAdSpend $record): bool => RoleGate::adminOnly() && ! $record->is_locked)
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
            ])
            ->emptyStateHeading('Пока нет месяцев')
            ->emptyStateDescription('Добавьте бюджет по месяцу — лиды и стоимость лида посчитаются автоматически.');
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function formSchema(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\DatePicker::make('starts_at')
                    ->label('Период с')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->default(now()->startOfMonth())
                    ->live(),
                Forms\Components\DatePicker::make('ends_at')
                    ->label('по')
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->default(now())
                    ->minDate(fn (Forms\Get $get) => $get('starts_at') ?: null)
                    ->live(),
                Forms\Components\TextInput::make('specialist_salary')
                    ->label('Зарплата специалиста, ₽')
                    ->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Forms\Components\TextInput::make('ad_budget')
                    ->label('Пополнение кабинета Директа, ₽')
                    ->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Forms\Components\Select::make('landing_page_id')
                    ->label('Лендинг (необязательно)')
                    ->options(fn () => LandingPage::orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->placeholder('Все лендинги')
                    ->live(),
                Forms\Components\Placeholder::make('leads_preview')
                    ->label('Лидов за период')
                    ->content(fn (Forms\Get $get): string => (string) self::leadsForState($get)),
                Forms\Components\Placeholder::make('cost_preview')
                    ->label('Стоимость лида')
                    ->content(function (Forms\Get $get): string {
                        $leads = self::leadsForState($get);
                        if ($leads <= 0) {
                            return '—';
                        }

                        return self::money(((float) $get('specialist_salary') + (float) $get('ad_budget')) / $leads);
                    }),
            ]),
        ];
    }

    private static function leadsForState(Forms\Get $get): int
    {
        $from = $get('starts_at');
        $to = $get('ends_at');
        if (empty($from) || empty($to)) {
            return 0;
        }

        return Lead::countForPeriod(
            Carbon::parse($from),
            Carbon::parse($to),
            $get('utm_source') ?: null,
            $get('landing_page_id') ?: null,
        );
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ' ').' ₽';
    }
}
