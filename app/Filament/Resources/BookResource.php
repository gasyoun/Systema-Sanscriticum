<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BookResource\Pages;
use App\Models\Book;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Каталог книг «Книжного клуба» (H8412): книги, цена и ОСТАТОК.
 *
 * books.stock — единственный источник остатка (Q6/Q9), правится только здесь;
 * при 0 витрина /knizhny-klub/kupit сама показывает «под заказ 2–3 недели».
 * Ресурс за фича-флагом features.book_club_shop (как и витрина).
 */
class BookResource extends Resource
{
    protected static ?string $model = Book::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Книжный клуб';

    protected static ?int $navigationSort = 130;

    protected static ?string $navigationLabel = 'Книги';

    protected static ?string $pluralModelLabel = 'Книги';

    public static function shouldRegisterNavigation(): bool
    {
        return static::enabled();
    }

    public static function canViewAny(): bool
    {
        return static::enabled();
    }

    public static function canCreate(): bool
    {
        return static::enabled();
    }

    public static function canEdit($record): bool
    {
        return static::enabled();
    }

    public static function canDelete($record): bool
    {
        return static::enabled();
    }

    public static function canDeleteAny(): bool
    {
        return static::enabled();
    }

    private static function enabled(): bool
    {
        return (bool) config('features.book_club_shop') && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Книга')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('slug')
                        ->label('Код (slug)')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('price')
                        ->label('Цена за экземпляр, ₽')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(0.01),

                    Forms\Components\TextInput::make('stock')
                        ->label('Остаток, экз.')
                        ->numeric()
                        ->required()
                        ->helperText('Единственный источник остатка. 0 и меньше — витрина показывает «под заказ 2–3 недели» и продолжает принимать заказы.'),

                    Forms\Components\TextInput::make('weight_g')
                        ->label('Вес экземпляра, г')
                        ->numeric()
                        ->helperText('Пока неизвестен — заполнить из паспорта тиража.'),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок в каталоге')
                        ->numeric()
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Показывать на витрине')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('Цена, ₽')
                    ->money('RUB', divideBy: 1)
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Остаток')
                    ->badge()
                    ->color(fn (Book $record): string => $record->inStock() ? 'success' : 'warning')
                    ->formatStateUsing(fn (Book $record): string => $record->inStock()
                        ? (string) $record->stock
                        : ($record->stock < 0 ? $record->stock.' (очередь)' : '0 — под заказ'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('На витрине')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListBooks::route('/'),
            'create' => Pages\CreateBook::route('/create'),
            'edit' => Pages\EditBook::route('/{record}/edit'),
        ];
    }
}
