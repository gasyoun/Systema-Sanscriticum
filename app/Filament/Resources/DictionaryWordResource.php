<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DictionaryWordResource\Pages;
use App\Models\DictionaryWord;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DictionaryWordResource extends Resource
{
    protected static ?string $model = DictionaryWord::class;

    // Иконка и навигация
    protected static ?string $navigationIcon = 'heroicon-o-language';
    protected static ?string $navigationGroup = 'Допматериалы';
    protected static ?string $modelLabel = 'Слово';
    protected static ?string $pluralModelLabel = 'Словарный запас';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')->schema([
                    // Выпадающий список вместо ввода ID цифрами
                    Forms\Components\Select::make('dictionary_id')
                        ->relationship('dictionary', 'name')
                        ->required()
                        ->label('Словарь')
                        ->searchable()
                        ->preload(),
                        
                    Forms\Components\TextInput::make('page')
                        ->maxLength(255)
                        ->label('Страница/Источник')
                        ->default(null),
                ])->columns(2),

                Forms\Components\Section::make('Написание и чтение')->schema([
                    Forms\Components\TextInput::make('devanagari')
                        ->maxLength(255)
                        ->label('Деванагари')
                        ->default(null),
                    Forms\Components\TextInput::make('iast')
                        ->maxLength(255)
                        ->label('IAST (Транслитерация)')
                        ->default(null),
                    Forms\Components\TextInput::make('cyrillic')
                        ->maxLength(255)
                        ->label('Кириллица')
                        ->default(null),
                ])->columns(3),

                Forms\Components\Section::make('Значение')->schema([
                    Forms\Components\Textarea::make('translation')
                        ->required()
                        ->label('Перевод')
                        ->rows(5)
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('dictionary.name')
                    ->label('Словарь')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('devanagari')
                    ->label('Деванагари')
                    ->searchable(),
                Tables\Columns\TextColumn::make('iast')
                    ->label('IAST')
                    ->searchable(),
                Tables\Columns\TextColumn::make('translation')
                    ->label('Перевод')
                    ->limit(50) // Обрезаем длинный текст в таблице
                    ->searchable(),
                Tables\Columns\TextColumn::make('page')
                    ->label('Стр.')
                    ->searchable(),
            ])
            ->filters([
                // Фильтр по словарю
                Tables\Filters\SelectFilter::make('dictionary_id')
                    ->relationship('dictionary', 'name')
                    ->label('Фильтр по словарю'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDictionaryWords::route('/'),
            'create' => Pages\CreateDictionaryWord::route('/create'),
            'edit' => Pages\EditDictionaryWord::route('/{record}/edit'),
        ];
    }
}