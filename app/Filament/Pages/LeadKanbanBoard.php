<?php

namespace App\Filament\Pages;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

/**
 * Канбан-доска заявок (GC-C1 / H451) — drag-drop по стадиям lead_stages,
 * заменяет/дополняет плоскую таблицу LeadResource. Тот же гард финальных
 * стадий, что и в LeadResource (Lead::blocksRollbackToFirstStage) —
 * автоконверсия по оплате не трогается, доска только читает/пишет status.
 */
class LeadKanbanBoard extends KanbanBoard
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 71;

    protected static ?string $navigationLabel = 'Заявки — доска';

    protected static ?string $title = 'Заявки (доска)';

    protected static ?string $slug = 'leads-board';

    protected static string $model = Lead::class;

    protected static string $recordTitleAttribute = 'kanban_title';

    protected static string $recordStatusAttribute = 'status';

    public bool $disableEditModal = true;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    protected function statuses(): Collection
    {
        return LeadStage::query()->ordered()->get()
            ->map(fn (LeadStage $stage): array => [
                'id' => $stage->key,
                'title' => $stage->label,
                'color' => $stage->color,
            ]);
    }

    protected function getEloquentQuery(): Builder
    {
        return Lead::query()->orderByDesc('created_at');
    }

    public function onStatusChanged(int|string $recordId, string $status, array $fromOrderedIds, array $toOrderedIds): void
    {
        $record = $this->getEloquentQuery()->find($recordId);

        if (! $record) {
            return;
        }

        if (Lead::blocksRollbackToFirstStage($record->status, $status)) {
            Notification::make()
                ->title('Нельзя вернуть финальный статус в первую стадию')
                ->body('Сначала смените «Конверсию»/«Отказ» на промежуточную стадию в карточке лида.')
                ->danger()
                ->send();

            return;
        }

        $record->update(['status' => $status]);
    }
}
