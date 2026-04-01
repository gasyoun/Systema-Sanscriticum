<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DictionaryResource\Pages;
use App\Models\Dictionary;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DictionaryResource extends Resource
{
    protected static ?string $model = Dictionary::class;

    // Иконки и перевод для левого меню
    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Словари';
    protected static ?string $pluralModelLabel = 'Словари';
    protected static ?string $navigationGroup = 'Допматериалы'; // Будет рядом со "Словами"

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки словаря')
                    ->schema([
                        Forms\Components\TextInput::make('name') // Правильное название колонки!
                            ->label('Название словаря')
                            ->required()
                            ->maxLength(255),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->label('Активен (виден студентам)')
                            ->default(true)
                            ->onColor('success'),
                            
                        Forms\Components\Textarea::make('description')
                            ->label('Описание (необязательно)')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID') // Тот самый ID, который нужен для CSV!
                    ->sortable()
                    ->badge() 
                    ->color('info'),
                    
                Tables\Columns\TextColumn::make('name') // Правильное название колонки!
                    ->label('Название')
                    ->searchable(),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                    
                Tables\Columns\TextColumn::make('words_count')
                    ->counts('words') // Автоматически считает привязанные слова
                    ->label('Кол-во слов')
                    ->badge(),
            ])
            ->defaultSort('id', 'desc')
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
            'index' => Pages\ListDictionaries::route('/'),
            'create' => Pages\CreateDictionary::route('/create'),
            'edit' => Pages\EditDictionary::route('/{record}/edit'),
        ];
    }
}