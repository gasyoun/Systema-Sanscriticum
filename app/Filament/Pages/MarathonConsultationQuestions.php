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
 * H440/H487 — ТОЛЬКО ЧТЕНИЕ. Вопросы к живой консультации Дня 3 марафона,
 * собранные на странице Дня 2 (H483, MarathonController::completeDay()).
 * Платный трек первым — их вопрос разбирают лично на эфире; бесплатный —
 * для общего контекста ведущему. Ничего не рассылает и не редактирует.
 */
class MarathonConsultationQuestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Вопросы марафона (День 3)';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static string $view = 'filament.pages.marathon-consultation-questions';

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
                ->whereNotNull('day2_question'))
            ->recordTitleAttribute('day2_question')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            // 'paid' > 'free' alphabetically — desc puts the paid track (personally
            // addressed on the live call) first, matching the page's stated purpose.
            ->defaultSort('track', 'desc')
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

                Tables\Columns\TextColumn::make('day2_question')
                    ->label('Вопрос к консультации')
                    ->wrap()
                    ->width('50%'),

                Tables\Columns\TextColumn::make('quiz_goal')
                    ->label('Цель')
                    ->badge(),

                Tables\Columns\TextColumn::make('day2_engaged_at')
                    ->label('Вопрос оставлен')
                    ->dateTime('d.m H:i')
                    ->sortable(),
            ]);
    }
}
