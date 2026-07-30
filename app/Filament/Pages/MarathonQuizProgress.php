<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\MarathonEnrollment;
use App\Support\RoleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin view of Day 1/2 quiz engagement + wall-clock duration.
 * Actions: reset Day 1 / Day 2 / both so a student can re-take the tap-choice
 * (clears day{N}_engaged_at + day{N}_quiz_seconds only).
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
            ->query(fn (): Builder => MarathonEnrollment::query()->with('lead'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('track')
                    ->label('Трек')
                    ->badge()
                    ->color(fn (string $state): string => $state === MarathonEnrollment::TRACK_PAID ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === MarathonEnrollment::TRACK_PAID ? 'С проверкой ₽' : 'Free')
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead.name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead.contact')
                    ->label('Контакт')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('lead.magnet_token')
                    ->label('Токен')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ])
            ->actions([
                Tables\Actions\Action::make('resetDay1')
                    ->label('Сброс Д1')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Сбросить опрос Дня 1?')
                    ->modalDescription('Участник снова увидит квиз по токену (day1_engaged_at и время обнулятся). Доставка TG/email не трогается.')
                    ->visible(fn (MarathonEnrollment $record): bool => $record->day1_engaged_at !== null)
                    ->action(function (MarathonEnrollment $record): void {
                        $record->resetQuizEngagement(1);
                        Notification::make()->title('День 1 сброшен')->success()->send();
                    }),

                Tables\Actions\Action::make('resetDay2')
                    ->label('Сброс Д2')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Сбросить опрос Дня 2?')
                    ->modalDescription('Участник снова увидит квиз Дня 2. Вопрос к консультации (day2_question) сохраняется.')
                    ->visible(fn (MarathonEnrollment $record): bool => $record->day2_engaged_at !== null)
                    ->action(function (MarathonEnrollment $record): void {
                        $record->resetQuizEngagement(2);
                        Notification::make()->title('День 2 сброшен')->success()->send();
                    }),

                Tables\Actions\Action::make('resetBoth')
                    ->label('Сброс Д1+Д2')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Сбросить оба опроса?')
                    ->modalDescription('Обнуляет day1/day2 engaged + время. Вопрос к консультации и paid/drip-часы не трогаются.')
                    ->visible(fn (MarathonEnrollment $record): bool => $record->day1_engaged_at !== null || $record->day2_engaged_at !== null)
                    ->action(function (MarathonEnrollment $record): void {
                        $record->resetQuizEngagement(null);
                        Notification::make()->title('Опросы Д1+Д2 сброшены')->success()->send();
                    }),
            ]);
    }
}
