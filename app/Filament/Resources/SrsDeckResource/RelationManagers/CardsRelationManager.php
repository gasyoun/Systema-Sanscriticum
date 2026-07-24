<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsDeckResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $title = 'Карточки';

    protected static ?string $modelLabel = 'карточка';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('fields.devanagari')
                ->label('Деванагари')
                ->maxLength(255),
            Forms\Components\TextInput::make('fields.iast')
                ->label('IAST')
                ->maxLength(255),
            Forms\Components\TextInput::make('fields.cyrillic')
                ->label('Кириллица')
                ->maxLength(255),
            Forms\Components\Textarea::make('fields.translation')
                ->label('Перевод')
                ->required()
                ->rows(2)
                ->columnSpanFull(),
            Forms\Components\Select::make('direction')
                ->label('Направление')
                ->options([
                    'front_back' => 'Скрипт → перевод',
                    'back_front' => 'Перевод → скрипт',
                ])
                ->default('front_back')
                ->required()
                ->native(false),
            Forms\Components\Select::make('source_word_id')
                ->label('Слово словаря')
                ->relationship('sourceWord', 'iast')
                ->searchable()
                ->nullable()
                ->helperText('Опциональная ссылка на DictionaryWord.'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('fields.devanagari')
                    ->label('Деванагари')
                    ->searchable(query: function ($query, string $search) {
                        return $query->where('fields->devanagari', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('fields.iast')
                    ->label('IAST')
                    ->searchable(query: function ($query, string $search) {
                        return $query->where('fields->iast', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('fields.translation')
                    ->label('Перевод')
                    ->wrap()
                    ->limit(60),
                Tables\Columns\TextColumn::make('direction')
                    ->label('Напр.')
                    ->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Добавить карточку'),
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
