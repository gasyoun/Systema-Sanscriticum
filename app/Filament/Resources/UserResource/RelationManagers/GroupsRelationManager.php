<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Services\GroupMembershipManager;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Состав групп студента с «мягким выходом»: участник остаётся в группе ради
 * доступа к оплаченным урокам, но может быть скрыт из активного состава
 * (ростеры/напоминания/чаты). См. GroupMembershipManager.
 *
 * Это НЕ замена мультиселекту «Программы / Доступы» в форме UserResource:
 * тот снимает доступ (жёсткий detach). Здесь — «больше не учится, но доступ
 * сохраняется».
 */
class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'groups';

    protected static ?string $title = 'Группы (активный состав)';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Группа')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('membership_status')
                    ->label('Статус в группе')
                    ->badge()
                    ->getStateUsing(fn ($record): string => $record->pivot->left_at ? 'Вышел' : 'Активен')
                    ->color(fn (string $state): string => $state === 'Активен' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('pivot.left_reason')
                    ->label('Причина')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        GroupMembershipManager::REASON_MANUAL => 'вручную',
                        GroupMembershipManager::REASON_STATUS => 'по статусу курса',
                        default => '—',
                    }),

                Tables\Columns\TextColumn::make('pivot.left_at')
                    ->label('Вышел')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('membership')
                    ->label('Состав')
                    ->placeholder('Только активные')
                    ->trueLabel('Только вышедшие')
                    ->falseLabel('Все')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('group_user.left_at'),
                        false: fn (Builder $q): Builder => $q,
                        blank: fn (Builder $q): Builder => $q->whereNull('group_user.left_at'),
                    ),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('leave')
                    ->label('Исключить (сохранить доступ)')
                    ->icon('heroicon-m-user-minus')
                    ->color('danger')
                    ->visible(fn ($record): bool => $record->pivot->left_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Исключить из активного состава группы')
                    ->modalDescription('Студент будет скрыт из активного состава группы (ростеры, напоминания, чаты), но СОХРАНИТ доступ к оплаченным урокам.')
                    ->action(fn ($record) => app(GroupMembershipManager::class)->softLeave(
                        $this->getOwnerRecord(),
                        [$record->id],
                        GroupMembershipManager::REASON_MANUAL,
                    )),

                Tables\Actions\Action::make('restore')
                    ->label('Вернуть в группу')
                    ->icon('heroicon-m-user-plus')
                    ->color('success')
                    ->visible(fn ($record): bool => $record->pivot->left_at !== null)
                    ->action(fn ($record) => app(GroupMembershipManager::class)->restore(
                        $this->getOwnerRecord(),
                        [$record->id],
                        GroupMembershipManager::REASON_MANUAL,
                    )),
            ])
            ->bulkActions([]);
    }
}
