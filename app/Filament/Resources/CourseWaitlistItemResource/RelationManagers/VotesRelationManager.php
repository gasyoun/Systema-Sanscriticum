<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseWaitlistItemResource\RelationManagers;

use App\Models\WaitlistVote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * H4206: голоса по строке списка ожидания — кто голосовал и какое
 * пожелание времени слота оставил (утро/день/вечер). Куратор подбирает
 * слот по этим данным (кейс MihailProfiT67: «утром до 11:00»).
 */
class VotesRelationManager extends RelationManager
{
    protected static string $relationship = 'votes';

    protected static ?string $title = 'Голоса';

    protected static ?string $icon = 'heroicon-o-hand-raised';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Ученик')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('slot_preference')
                    ->label('Когда удобно')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WaitlistVote::SLOT_PREFERENCES[$state] ?? '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'morning' => 'warning',
                        'day' => 'info',
                        'evening' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Голос отдан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('slot_preference')
                    ->label('Когда удобно')
                    ->options(WaitlistVote::SLOT_PREFERENCES),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
