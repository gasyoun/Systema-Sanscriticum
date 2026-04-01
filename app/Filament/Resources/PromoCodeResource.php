<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    // Иконка билетика/купона для меню
    protected static ?string $navigationIcon = 'heroicon-o-ticket';
    
    // Создадим отдельную группу в меню для будущих фишек
    protected static ?string $navigationGroup = 'Маркетинг';
    protected static ?string $navigationLabel = 'Промокоды';
    protected static ?string $modelLabel = 'Промокод';
    protected static ?string $pluralModelLabel = 'Промокоды';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки промокода')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Сам промокод (Код)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase']) // Визуально делаем заглавными
                            ->helperText('То, что будет вводить студент. Например: SANSKRIT20'),

                        Forms\Components\Select::make('type')
                            ->label('Тип скидки')
                            ->options([
                                'percent' => 'Процент (%)',
                                'fixed' => 'Сумма (₽)',
                            ])
                            ->required()
                            ->default('percent'),

                        Forms\Components\TextInput::make('value')
                            ->label('Размер скидки')
                            ->required()
                            ->numeric()
                            ->helperText('Укажите число (например 20 для процентов, или 1000 для рублей).'),

                        Forms\Components\TextInput::make('usage_limit')
                            ->label('Лимит активаций')
                            ->numeric()
                            ->helperText('Оставьте пустым, если код могут применять бесконечное количество раз.'),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Срок действия (до)'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Активен (можно применить)')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->weight('bold')
                    ->copyable() // Удобно копировать кликом
                    ->copyMessage('Промокод скопирован!'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'percent' => 'Процент (%)',
                        'fixed' => 'Сумма (₽)',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'percent' => 'info',
                        'fixed' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->label('Скидка')
                    ->formatStateUsing(function ($record) {
                        return $record->type === 'percent' 
                            ? $record->value . ' %' 
                            : number_format($record->value, 0, '.', ' ') . ' ₽';
                    }),

                Tables\Columns\TextColumn::make('usage_limit')
                    ->label('Использовано')
                    ->formatStateUsing(function ($record) {
                        $limit = $record->usage_limit ? ' из ' . $record->usage_limit : ' (безлимит)';
                        return $record->used_count . $limit;
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Действует до')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('Бессрочно'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Активен'),
            ])
            ->filters([
                // Здесь можно будет добавить фильтры "Только активные", "Просроченные"
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }
}