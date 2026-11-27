<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BookClubOrderResource\Pages;
use App\Models\BookClubOrder;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Заказы «Книжного клуба» (H8412): одна очередь фулфилмента для доставки
 * участникам кампании (H8195) и покупок магазина.
 *
 * Статусы: pending → paid → packed → shipped (+ cancelled). Переход в shipped
 * требует трек-номер и ставит покупателю письмо в очередь (BookClubOrder).
 * CSV-экспорт — на списке заказов.
 */
class BookClubOrderResource extends Resource
{
    protected static ?string $model = BookClubOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Книжный клуб';

    protected static ?int $navigationSort = 131;

    protected static ?string $navigationLabel = 'Заказы клуба';

    protected static ?string $pluralModelLabel = 'Заказы клуба';

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
        // Заказы приходят только с витрин — вручную не создаются.
        return false;
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
        $flags = (bool) config('features.book_club_shop') || (bool) config('features.book_club_delivery');

        return $flags && RoleGate::adminOnly();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::enabled()) {
            return null;
        }

        $pending = BookClubOrder::query()->where('status', BookClubOrder::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Фулфилмент')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(BookClubOrder::statusOptions())
                        ->required(),

                    Forms\Components\TextInput::make('tracking_number')
                        ->label('Трек-номер')
                        ->maxLength(64)
                        ->required(fn (Forms\Get $get): bool => $get('status') === BookClubOrder::STATUS_SHIPPED)
                        ->helperText('Обязателен при переходе в «Отправлен»: покупателю уйдёт письмо с ним.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Заказ')
                ->schema([
                    Forms\Components\TextInput::make('mode')
                        ->label('Режим')
                        ->disabled()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            BookClubOrder::MODE_PICKUP => 'Самовывоз',
                            BookClubOrder::MODE_PURCHASE => 'Покупка книги + доставка',
                            default => 'Доставка участнику кампании',
                        }),

                    Forms\Components\TextInput::make('book.title')
                        ->label('Книга')
                        ->disabled(),

                    Forms\Components\TextInput::make('books_count')
                        ->label('Экземпляров')
                        ->disabled(),

                    Forms\Components\TextInput::make('amount')
                        ->label('Сумма, ₽')
                        ->disabled(),

                    Forms\Components\TextInput::make('delivery_amount')
                        ->label('Доставка, ₽')
                        ->disabled(),

                    Forms\Components\TextInput::make('user.email')
                        ->label('Покупатель')
                        ->disabled(),
                ])
                ->columns(3),

            Forms\Components\Section::make('Доставка')
                ->schema([
                    Forms\Components\TextInput::make('recipient_name')
                        ->label('Получатель')
                        ->disabled(),

                    Forms\Components\TextInput::make('phone')
                        ->label('Телефон')
                        ->disabled(),

                    Forms\Components\TextInput::make('country_name')
                        ->label('Страна')
                        ->disabled()
                        ->formatStateUsing(fn (?string $state, ?BookClubOrder $record): string => $state ?: (string) ($record?->country_code ?? '—')),

                    Forms\Components\TextInput::make('city')
                        ->label('Город')
                        ->disabled(),

                    Forms\Components\TextInput::make('postal_code')
                        ->label('Индекс')
                        ->disabled(),

                    Forms\Components\TextInput::make('address_line')
                        ->label('Адрес')
                        ->disabled()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('planeta_email')
                        ->label('Email на planeta')
                        ->disabled(),

                    Forms\Components\TextInput::make('planeta_order_number')
                        ->label('Заказ planeta')
                        ->disabled(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('№')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('mode')
                    ->label('Режим')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BookClubOrder::MODE_PICKUP => 'Самовывоз',
                        BookClubOrder::MODE_PURCHASE => 'Покупка',
                        default => 'Доставка',
                    }),

                Tables\Columns\TextColumn::make('book.title')
                    ->label('Книга')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('books_count')
                    ->label('Экз.')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Покупатель')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма, ₽')
                    ->money('RUB', divideBy: 1)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (BookClubOrder $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        BookClubOrder::STATUS_SHIPPED => 'success',
                        BookClubOrder::STATUS_PAID, BookClubOrder::STATUS_PACKED => 'info',
                        BookClubOrder::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Трек')
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(BookClubOrder::statusOptions()),

                Tables\Filters\SelectFilter::make('mode')
                    ->label('Режим')
                    ->options([
                        BookClubOrder::MODE_DELIVERY_ONLY => 'Доставка участнику кампании',
                        BookClubOrder::MODE_PURCHASE => 'Покупка книги',
                        BookClubOrder::MODE_PICKUP => 'Самовывоз',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookClubOrders::route('/'),
            'edit' => Pages\EditBookClubOrder::route('/{record}/edit'),
        ];
    }
}
