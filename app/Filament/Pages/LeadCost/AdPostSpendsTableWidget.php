<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Models\AdPostSpend;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AdPostSpendsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Рекламные посты';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AdPostSpend::query())
            ->defaultSort('posted_on', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить пост')
                    ->modalHeading('Рекламный пост')
                    ->form($this->formSchema())
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('posted_on')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Название / площадка')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('budget')
                    ->label('Бюджет')
                    ->money('RUB')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('RUB')->label('Итого')),
                Tables\Columns\TextColumn::make('writers_count')
                    ->label('Написавших')
                    ->alignRight()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Итого')),
                Tables\Columns\TextColumn::make('cost_per_writer')
                    ->label('Стоимость написавшего')
                    ->badge()
                    ->getStateUsing(fn (AdPostSpend $record): string => $record->costPerWriter() === null
                        ? '—'
                        : self::money($record->costPerWriter()))
                    ->color(fn (AdPostSpend $record): string => $record->costPerWriter() === null ? 'gray' : 'success'),
                Tables\Columns\IconColumn::make('is_locked')
                    ->label('Замок')
                    ->boolean()
                    ->trueIcon('heroicon-s-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (AdPostSpend $record): string => $record->is_locked
                        ? 'Заблокировано от правки/удаления'
                        : 'Открыто для правки')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form($this->formSchema())
                    // Заблокированную (is_locked) историю правит только админ.
                    ->visible(fn (AdPostSpend $record): bool => ! $record->is_locked || RoleGate::adminOnly())
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
                Tables\Actions\DeleteAction::make()
                    // Удаление истории — только админ и только если не заблокировано.
                    ->visible(fn (AdPostSpend $record): bool => RoleGate::adminOnly() && ! $record->is_locked)
                    ->after(fn () => $this->dispatch('leadCostUpdated')),
            ])
            ->emptyStateHeading('Пока нет постов')
            ->emptyStateDescription('Добавьте рекламный пост — стоимость написавшего посчитается автоматически.');
    }

    /**
     * @return array<int, Component>
     */
    private function formSchema(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\DatePicker::make('posted_on')
                    ->label('Дата поста')
                    ->required()
                    ->native(false)
                    ->default(now()),
                Forms\Components\TextInput::make('title')
                    ->label('Название / площадка')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Напр.: пост в канале «...»'),
                Forms\Components\TextInput::make('budget')
                    ->label('Бюджет за пост, ₽')
                    ->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Forms\Components\TextInput::make('writers_count')
                    ->label('Написавших человек')
                    ->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Forms\Components\Placeholder::make('cost_preview')
                    ->label('Стоимость написавшего')
                    ->content(function (Forms\Get $get): string {
                        $writers = (int) $get('writers_count');
                        if ($writers <= 0) {
                            return '—';
                        }

                        return self::money((float) $get('budget') / $writers);
                    }),
                Forms\Components\Textarea::make('note')
                    ->label('Комментарий')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_locked')
                    ->label('Заблокировать запись')
                    ->helperText('Защита истории: заблокированную запись нельзя править/удалять (снять замок может только админ).')
                    ->visible(fn (): bool => RoleGate::adminOnly())
                    ->columnSpanFull(),
            ]),
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ' ').' ₽';
    }
}
