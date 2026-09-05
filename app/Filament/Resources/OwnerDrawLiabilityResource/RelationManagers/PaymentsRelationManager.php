<?php

declare(strict_types=1);

namespace App\Filament\Resources\OwnerDrawLiabilityResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Append-only выплаты по обязательству (H4188 п.4): только создать; править
 * и удалять нельзя — исправление новой записью (реестр 05-09).
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label('Сумма')
                ->numeric()
                ->required(),

            Forms\Components\DatePicker::make('paid_at')
                ->label('Дата выплаты')
                ->default(now())
                ->maxDate(now()->endOfDay())
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('reference')
                ->label('Ссылка (PayPal/выписка)')
                ->maxLength(500)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('paid_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Ссылка')
                    ->limit(40),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                // Append-only: edit/delete записи выплаты не предлагаются.
            ])
            ->bulkActions([
                // Пусто: append-only.
            ]);
    }
}
