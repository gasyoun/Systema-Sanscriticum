<?php

namespace App\Filament\Pages;

use App\Models\VacationQuorumPoll;
use App\Services\VacationQuorumService;
use App\Support\RoleGate;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Очередь одобрений о распускании каникульной группы (H3790, фаза C).
 *
 * Второй (после inline-кнопки в админ-чате) канал одобрения Гасунса:
 * решение одно, канала два, дедуп — по outcome опроса (одного одобрения
 * хватать должно ровно одному каналу).
 */
class VacationQuorumApprovals extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Каникулы: кворум';

    protected static ?string $title = 'Каникулы: опросы кворума';

    protected static string $view = 'filament.pages.vacation-quorum-approvals';

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
            ->query(
                fn () => VacationQuorumPoll::query()
                    ->with('group')
                    ->orderByRaw(sprintf(
                        "field(outcome, '%s', '%s', '%s', '%s', '%s')",
                        VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING,
                        VacationQuorumPoll::OUTCOME_PENDING,
                        VacationQuorumPoll::OUTCOME_QUORUM_MET,
                        VacationQuorumPoll::OUTCOME_DISSOLVED,
                        VacationQuorumPoll::OUTCOME_DECLINED,
                    ))
                    ->orderByDesc('deadline_at'),
            )
            ->columns([
                TextColumn::make('group.name')->label('Группа')->searchable(),
                TextColumn::make('chat_id')->label('Чат'),
                TextColumn::make('paid_voters_count')
                    ->label('Голоса')
                    ->state(fn (VacationQuorumPoll $record): string => count($record->paid_voters ?? []).' / '.($record->quorum_required ?? '—')),
                TextColumn::make('deadline_at')
                    ->label('Дедлайн')
                    ->dateTime('d.m H:i', 'Europe/Moscow'),
                TextColumn::make('outcome')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VacationQuorumPoll::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING => 'warning',
                        VacationQuorumPoll::OUTCOME_PENDING => 'info',
                        VacationQuorumPoll::OUTCOME_QUORUM_MET => 'success',
                        VacationQuorumPoll::OUTCOME_DISSOLVED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Распустить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Будущие занятия группы снимутся с расписания, группа уедет в архив. Обратимо только вручную.')
                    ->visible(fn (VacationQuorumPoll $record): bool => $record->outcome === VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING)
                    ->action(function (VacationQuorumPoll $record): void {
                        app(VacationQuorumService::class)->approveDissolution($record, auth()->user());
                    }),
                Action::make('decline')
                    ->label('Оставить')
                    ->visible(fn (VacationQuorumPoll $record): bool => $record->outcome === VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING)
                    ->action(function (VacationQuorumPoll $record): void {
                        app(VacationQuorumService::class)->declineDissolution($record, auth()->user());
                    }),
            ])
            ->paginated([10, 25]);
    }
}
