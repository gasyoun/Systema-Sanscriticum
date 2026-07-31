<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DictionaryWordResource\Pages;
use App\Models\DictionaryWord;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Lexical stock CRUD for admins and teachers (H2050: Elena Trefilova main
 * maintainer). Containers ({@see DictionaryResource}) stay admin-only.
 */
class DictionaryWordResource extends Resource
{
    protected static ?string $model = DictionaryWord::class;

    // Иконка и навигация
    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'Допматериалы';

    protected static ?string $modelLabel = 'Слово';

    protected static ?string $pluralModelLabel = 'Словарный запас';

    public static function canMaintain(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canViewAny(): bool
    {
        return self::canMaintain();
    }

    public static function canCreate(): bool
    {
        return self::canMaintain();
    }

    public static function canEdit($record): bool
    {
        return self::canMaintain();
    }

    public static function canDelete($record): bool
    {
        return self::canMaintain();
    }

    public static function canDeleteAny(): bool
    {
        return self::canMaintain();
    }

    // По каким полям искать, если мы введем текст в самый верхний (глобальный) поиск админки
    public static function getGloballySearchableAttributes(): array
    {
        return ['devanagari', 'iast', 'cyrillic', 'translation'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')->schema([
                    Forms\Components\Select::make('dictionary_id')
                        ->relationship('dictionary', 'name') // ИСПРАВЛЕНО: было 'name', а у нас в базе 'title'
                        ->required()
                        ->label('Словарь')
                        ->searchable()
                        ->preload(),

                    Forms\Components\TextInput::make('page')
                        ->maxLength(255)
                        ->label('Страница/Источник')
                        ->placeholder('Например: стр. 42')
                        ->default(null),
                ])->columns(2),

                Forms\Components\Section::make('Написание и чтение')->schema([
                    Forms\Components\TextInput::make('devanagari')
                        ->maxLength(255)
                        ->label('Деванагари')
                        ->placeholder('Например: नमस्ते')
                        ->default(null),

                    Forms\Components\TextInput::make('iast')
                        ->maxLength(255)
                        ->label('IAST (Транслитерация)')
                        ->placeholder('Например: namaste')
                        ->default(null),

                    Forms\Components\TextInput::make('cyrillic')
                        ->maxLength(255)
                        ->label('Кириллица')
                        ->placeholder('Например: намасте')
                        ->default(null),
                ])->columns(3),

                Forms\Components\Section::make('Значение')->schema([
                    Forms\Components\Textarea::make('translation')
                        ->required()
                        ->label('Перевод')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('dictionary.name') // ИСПРАВЛЕНО: 'title' вместо 'name'
                    ->label('Словарь')
                    ->sortable()
                    ->badge() // Делаем название словаря красивой плашкой
                    ->searchable(),

                Tables\Columns\TextColumn::make('devanagari')
                    ->label('Деванагари')
                    ->searchable()
                    ->copyable() // Позволяет скопировать слово по клику!
                    ->copyMessage('Скопировано!'),

                Tables\Columns\TextColumn::make('iast')
                    ->label('IAST')
                    ->searchable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('translation')
                    ->label('Перевод')
                    ->wrap() // Если перевод длинный, он перенесется на новую строку, а не скроется
                    ->searchable(),

                Tables\Columns\TextColumn::make('page')
                    ->label('Стр.')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Можно скрыть, чтобы не загромождать таблицу
            ])
            ->defaultSort('id', 'desc') // Новые слова всегда сверху
            ->filters([
                // Фильтр по словарю
                Tables\Filters\SelectFilter::make('dictionary_id')
                    ->relationship('dictionary', 'name') // ИСПРАВЛЕНО: 'title' вместо 'name'
                    ->label('Фильтр по словарю')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(), // Добавил быструю кнопку удаления слова
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
