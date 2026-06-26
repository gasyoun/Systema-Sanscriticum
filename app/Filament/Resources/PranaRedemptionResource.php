<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PranaRedemptionResource\Pages;
use App\Models\PranaRedemption;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PranaRedemptionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PranaRedemption::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 131;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Покупки за прану';

    protected static ?string $pluralModelLabel = 'Покупки за прану';

    protected static ?string $modelLabel = 'Покупка';

    public static function canCreate(): bool
    {
        return false; // создаются только при покупке студентом
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', PranaRedemption::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    PranaRedemption::STATUS_PENDING => 'В обработке',
                    PranaRedemption::STATUS_FULFILLED => 'Выполнено',
                    PranaRedemption::STATUS_CANCELLED => 'Отменено',
                ])
                ->required(),
            Forms\Components\Textarea::make('admin_note')->label('Заметка администратора')->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Когда')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Студент')->searchable(),
                Tables\Columns\TextColumn::make('perk_name')->label('Перк')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('cost')->label('Цена 🪷')->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PranaRedemption::STATUS_FULFILLED => 'success',
                        PranaRedemption::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PranaRedemption::STATUS_FULFILLED => 'Выполнено',
                        PranaRedemption::STATUS_CANCELLED => 'Отменено',
                        default => 'В обработке',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    PranaRedemption::STATUS_PENDING => 'В обработке',
                    PranaRedemption::STATUS_FULFILLED => 'Выполнено',
                    PranaRedemption::STATUS_CANCELLED => 'Отменено',
                ])->default(PranaRedemption::STATUS_PENDING),
            ])
            ->actions([
                Tables\Actions\Action::make('fulfill')
                    ->label('Выполнить')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (PranaRedemption $r): bool => $r->status === PranaRedemption::STATUS_PENDING)
                    ->action(fn (PranaRedemption $r) => $r->update(['status' => PranaRedemption::STATUS_FULFILLED])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPranaRedemptions::route('/'),
            'edit' => Pages\EditPranaRedemption::route('/{record}/edit'),
        ];
    }
}
