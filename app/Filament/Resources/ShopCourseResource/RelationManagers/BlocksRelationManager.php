<?php

namespace App\Filament\Resources\ShopCourseResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    protected static ?string $title = 'Блоки курса';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->label('Номер блока')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->columnSpan(1),

                Forms\Components\TextInput::make('title')
                    ->label('Название блока')
                    ->placeholder('Например: «Морфология» или «Блок 1»')
                    ->maxLength(255)
                    ->columnSpan(2),

                Forms\Components\Textarea::make('description')
                    ->label('Описание')
                    ->rows(3)
                    ->columnSpanFull(),

                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Начало блока')
                    ->seconds(false)
                    ->native(false),

                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Окончание блока')
                    ->seconds(false)
                    ->native(false)
                    ->after('starts_at'),

                Forms\Components\Toggle::make('is_current')
                    ->label('Сейчас идёт (override)')
                    ->helperText('Принудительно помечает блок актуальным независимо от дат')
                    ->onColor('warning'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Активен (виден на витрине)')
                    ->default(true)
                    ->onColor('success'),
            ])
            ->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderBy('number'))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('№')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Начало')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Конец')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('current_now')
                    ->label('Сейчас')
                    ->boolean()
                    ->state(fn ($record) => $record->isCurrent()),

                Tables\Columns\TextColumn::make('tariff.price')
                    ->label('Цена тарифа')
                    ->placeholder('— нет тарифа —')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, '.', ' ') . ' ₽' : '—'),

                Tables\Columns\ToggleColumn::make('is_current')
                    ->label('Override'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Активен'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить блок')
                    ->icon('heroicon-o-plus'),
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
