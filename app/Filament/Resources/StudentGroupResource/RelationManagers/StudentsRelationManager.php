<?php

namespace App\Filament\Resources\StudentGroupResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StudentsRelationManager extends RelationManager
{
    // Активный состав: вышедшие/выпускники (group_user.left_at) скрыты — они
    // сохраняют доступ к урокам, но в текущем составе группы не показываются.
    protected static string $relationship = 'activeUsers';

    protected static ?string $title = 'Ученики группы';

    protected static ?string $recordTitleAttribute = 'name';

    /** Состав группы payment-driven — преподаватель только смотрит, без attach/detach/edit. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Последняя активность')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
