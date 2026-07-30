<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\MarathonEnrollment;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin view of Day 1/2 quiz engagement + wall-clock duration (posted by
 * the enrollee's browser on complete). Read-only.
 */
class MarathonQuizProgress extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Марафон: опросы';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.marathon-quiz-progress';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MarathonEnrollment::query()
                ->with('lead')
                ->where(function (Builder $q): void {
                    $q->whereNotNull('day1_engaged_at')
                        ->orWhereNotNull('day2_engaged_at');
                }))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->defaultSort('day1_engaged_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('track')
                    ->label('Трек')
                    ->badge()
                    ->color(fn (string $state): string => $state === MarathonEnrollment::TRACK_PAID ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === MarathonEnrollment::TRACK_PAID ? 'С проверкой ₽' : 'Free')
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead.name')
                    ->label('Имя')
                    ->description(fn (Model $r): string => (string) $r->lead?->contact)
                    ->searchable(),

                Tables\Columns\TextColumn::make('day1_engaged_at')
                    ->label('День 1')
                    ->dateTime('d.m H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('day1_quiz_seconds')
                    ->label('Д1 время')
                    ->formatStateUsing(fn (?int $state, MarathonEnrollment $record): string => $record->formatQuizDuration($state) ?? '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('day2_engaged_at')
                    ->label('День 2')
                    ->dateTime('d.m H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('day2_quiz_seconds')
                    ->label('Д2 время')
                    ->formatStateUsing(fn (?int $state, MarathonEnrollment $record): string => $record->formatQuizDuration($state) ?? '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('quiz_goal')
                    ->label('Цель')
                    ->badge(),
            ]);
    }
}
