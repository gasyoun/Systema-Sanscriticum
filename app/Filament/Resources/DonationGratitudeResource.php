<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DonationGratitudeResource\Pages;
use App\Models\DonationGratitude;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Реестр благодарностей меценатам (план института N3).
 *
 * Основное назначение — офлайн-доноры: переводы по реквизитам не проходят
 * через /mecenaty, их имена (по явному согласию человека) заводятся здесь
 * вручную. Онлайн-донаты попадают в реестр сами, из paid-вебхука Точки.
 * Колонки суммы в таблице нет намеренно (рулинг MG 23-08) — и в форме её
 * заводить нельзя.
 */
class DonationGratitudeResource extends Resource
{
    protected static ?string $model = DonationGratitude::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 76;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Благодарности меценатам';

    protected static ?string $modelLabel = 'благодарность';

    protected static ?string $pluralModelLabel = 'Благодарности меценатам';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Благодарность')->schema([
                Forms\Components\TextInput::make('name_display')
                    ->label('Имя в списке')
                    ->required()
                    ->maxLength(120),
                Forms\Components\Toggle::make('is_public')
                    ->label('Показывать на /mecenaty')
                    ->default(true)
                    ->helperText('Выключено = имя скрыто из публичного списка, строка остаётся в реестре.'),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_display')->label('Имя')->searchable(),
                Tables\Columns\IconColumn::make('is_public')->label('Публично')->boolean(),
                Tables\Columns\TextColumn::make('payment_id')->label('Онлайн-платёж')
                    ->placeholder('офлайн-запись'),
                Tables\Columns\TextColumn::make('created_at')->label('Добавлено')->dateTime('d.m.Y'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDonationGratitudes::route('/'),
            'create' => Pages\CreateDonationGratitude::route('/create'),
            'edit' => Pages\EditDonationGratitude::route('/{record}/edit'),
        ];
    }
}
