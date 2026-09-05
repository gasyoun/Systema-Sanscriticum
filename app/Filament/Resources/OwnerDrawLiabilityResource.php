<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\FinanceAccess;
use App\Filament\Resources\OwnerDrawLiabilityResource\Pages;
use App\Filament\Resources\OwnerDrawLiabilityResource\RelationManagers\PaymentsRelationManager;
use App\Models\OwnerDrawLiability;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Обязательства перед владельцем (owner draw, H4188 п.3/4): остаток по
 * февральской ноте книги, пара «выплачено / остаток» вместо прозы в нотах.
 * Выплаты — append-only записи в связанном менеджере; остаток пересчитывается.
 */
class OwnerDrawLiabilityResource extends Resource
{
    use FinanceAccess;

    protected static ?string $model = OwnerDrawLiability::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 84;

    protected static ?string $navigationLabel = 'Долг перед владельцем';

    protected static ?string $modelLabel = 'Обязательство';

    protected static ?string $pluralModelLabel = 'Долги перед владельцем';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Обязательство')
                ->description('Остаток перед владельцем в валюте; выплаты — append-only записи ниже.')
                ->schema([
                    Forms\Components\Select::make('currency')
                        ->label('Валюта')
                        ->options(['EUR' => 'EUR €', 'USD' => 'USD $', 'RUB' => 'RUB ₽'])
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('principal')
                        ->label('Зафиксированный остаток')
                        ->numeric()
                        ->required(),

                    Forms\Components\DatePicker::make('fixed_at')
                        ->label('Дата фиксации')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('note')
                        ->label('Основание')
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('currency')->label('Валюта')->badge(),
            TextEntry::make('principal')->label('Зафиксировано')->numeric(decimalPlaces: 2),
            TextEntry::make('paid')->label('Выплачено')->numeric(decimalPlaces: 2),
            TextEntry::make('remaining')->label('Остаток')->numeric(decimalPlaces: 2)
                ->weight('bold'),
            TextEntry::make('fixed_at')->label('Дата фиксации')->date('d.m.Y'),
            TextEntry::make('note')->label('Основание')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fixed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('currency')
                    ->label('Валюта')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('principal')
                    ->label('Зафиксировано')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid')
                    ->label('Выплачено')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Остаток')
                    ->numeric(decimalPlaces: 2)
                    ->weight('bold')
                    ->color(fn (string $state): string => (float) $state > 0 ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('fixed_at')
                    ->label('Дата фиксации')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Основание')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwnerDrawLiabilities::route('/'),
            'create' => Pages\CreateOwnerDrawLiability::route('/create'),
            'view' => Pages\ViewOwnerDrawLiability::route('/{record}'),
            'edit' => Pages\EditOwnerDrawLiability::route('/{record}/edit'),
        ];
    }
}
