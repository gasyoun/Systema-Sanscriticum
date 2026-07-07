<?php

namespace App\Filament\Resources\PartnerResource\RelationManagers;

use App\Models\PartnerConversion;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Клиенты, приведённые партнёром, и статус их вознаграждения. Начисления
 * создаёт PartnerService автоматически при оплате; здесь администратор
 * отмечает фактическую выплату (accrued → paid_out).
 */
class ConversionsRelationManager extends RelationManager
{
    protected static string $relationship = 'conversions';

    protected static ?string $title = 'Клиенты и выплаты';

    protected static ?string $modelLabel = 'начисление';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Клиент')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('reward_amount')
                    ->label('Вознаграждение, ₽')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0, '.', ' ')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PartnerConversion::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        PartnerConversion::STATUS_PAID_OUT => 'success',
                        PartnerConversion::STATUS_ACCRUED => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')->label('Начислено')->dateTime('d.m.Y')->sortable(),

                Tables\Columns\TextColumn::make('paid_out_at')->label('Выплачено')->dateTime('d.m.Y')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(PartnerConversion::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_paid')
                    ->label('Отметить выплату')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (PartnerConversion $r): bool => $r->status === PartnerConversion::STATUS_ACCRUED)
                    ->requiresConfirmation()
                    ->action(function (PartnerConversion $r): void {
                        $r->update([
                            'status' => PartnerConversion::STATUS_PAID_OUT,
                            'paid_out_at' => now(),
                        ]);
                        Notification::make()->title('Отмечено как выплачено')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('mark_paid_bulk')
                    ->label('Отметить выплату')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $n = 0;
                        foreach ($records as $record) {
                            /** @var PartnerConversion $record */
                            if ($record->status === PartnerConversion::STATUS_ACCRUED) {
                                $record->update([
                                    'status' => PartnerConversion::STATUS_PAID_OUT,
                                    'paid_out_at' => now(),
                                ]);
                                $n++;
                            }
                        }
                        Notification::make()->title("Отмечено выплат: {$n}")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
