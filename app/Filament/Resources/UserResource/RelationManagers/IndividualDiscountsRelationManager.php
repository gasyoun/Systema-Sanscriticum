<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\StudentDiscount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class IndividualDiscountsRelationManager extends RelationManager
{
    protected static string $relationship = 'individualDiscounts';

    protected static ?string $title = 'Индивидуальные скидки';

    protected static ?string $modelLabel = 'скидка';

    protected static ?string $pluralModelLabel = 'Индивидуальные скидки';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('block_number')
                    ->label('Блок')
                    ->numeric()
                    ->minValue(1)
                    ->placeholder('все блоки')
                    ->helperText('Пусто — постоянная скидка на все блоки курса. Номер — скидка только на этот блок (перебивает общую).'),

                Forms\Components\Select::make('type')
                    ->label('Тип скидки')
                    ->options([
                        StudentDiscount::TYPE_PERCENT => 'Процент (%)',
                        StudentDiscount::TYPE_FIXED => 'Фиксированная (₽)',
                    ])
                    ->default(StudentDiscount::TYPE_PERCENT)
                    ->required()
                    ->live(),

                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get): string => $get('type') === StudentDiscount::TYPE_FIXED ? 'Сумма скидки, ₽' : 'Процент скидки, %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Forms\Get $get): ?float => $get('type') === StudentDiscount::TYPE_PERCENT ? 100 : null)
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),

                Forms\Components\Textarea::make('note')
                    ->label('Примечание')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('block_number')
                    ->label('Блок')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => $state ? 'Блок '.$state : 'Все блоки'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === StudentDiscount::TYPE_FIXED ? 'Фикс' : 'Процент')
                    ->color(fn (string $state): string => $state === StudentDiscount::TYPE_FIXED ? 'warning' : 'info'),

                Tables\Columns\TextColumn::make('value')
                    ->label('Значение')
                    ->formatStateUsing(fn ($state, StudentDiscount $record): string => $record->type === StudentDiscount::TYPE_FIXED
                        ? number_format((float) $state, 0, '.', ' ').' ₽'
                        : (int) $state.' %'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Примечание')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Добавить скидку'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
