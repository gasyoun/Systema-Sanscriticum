<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\SrsCardResource\Pages;
use App\Models\SrsCard;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Standalone card CRUD + CSV import (Wave 2, H1487). Deck-scoped editing also
 * lives on SrsDeckResource's CardsRelationManager; this resource is for bulk
 * review/import across decks.
 */
class SrsCardResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SrsCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Допматериалы';

    protected static ?int $navigationSort = 41;

    protected static ?string $navigationLabel = 'SRS — карточки';

    protected static ?string $modelLabel = 'карточка SRS';

    protected static ?string $pluralModelLabel = 'SRS — карточки';

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canViewAny(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canDelete($record): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Select::make('deck_id')
                    ->label('Колода')
                    ->relationship('deck', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

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
                    ->nullable(),
            ])->columns(3),

            Forms\Components\Section::make('Поля карточки')->schema([
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
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('deck.name')
                    ->label('Колода')
                    ->searchable()
                    ->sortable(),

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
                    ->limit(50)
                    ->searchable(query: function ($query, string $search) {
                        return $query->where('fields->translation', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Напр.')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('deck_id')
                    ->label('Колода')
                    ->relationship('deck', 'name')
                    ->searchable()
                    ->preload(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSrsCards::route('/'),
            'create' => Pages\CreateSrsCard::route('/create'),
            'edit' => Pages\EditSrsCard::route('/{record}/edit'),
        ];
    }
}
