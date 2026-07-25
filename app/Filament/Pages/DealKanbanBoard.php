<?php

namespace App\Filament\Pages;

use App\Models\Deal;
use App\Models\DealStage;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

/**
 * Канбан-доска СДЕЛОК (GC-C1 / H1641) — drag-drop по стадиям deal_stages.
 *
 * Форма скопирована с LeadKanbanBoard (H451), но это ОТДЕЛЬНАЯ доска над
 * отдельной сущностью: доска заявок остаётся как есть и этой задачей не
 * заменяется — судьба двух досок вынесена человеку как открытая развилка.
 *
 * $statusEnum намеренно не задан: statuses() переопределён, а stage_id —
 * обычный integer без enum-каста (иначе filterRecordsByStatus() свалился бы
 * на static::$statusEnum::from()).
 */
class DealKanbanBoard extends KanbanBoard
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationLabel = 'Сделки — доска';

    protected static ?string $title = 'Сделки (доска)';

    protected static ?string $slug = 'deals-board';

    protected static string $model = Deal::class;

    protected static string $recordTitleAttribute = 'kanban_title';

    protected static string $recordStatusAttribute = 'stage_id';

    public bool $disableEditModal = true;

    public static function canAccess(): bool
    {
        return config('features.crm_pipeline_board')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.crm_pipeline_board')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    protected function statuses(): Collection
    {
        return DealStage::query()->ordered()->get()
            ->map(fn (DealStage $stage): array => [
                'id' => $stage->id,
                'title' => $stage->name,
            ]);
    }

    protected function getEloquentQuery(): Builder
    {
        return Deal::query()->with(['user', 'lead', 'course'])->orderByDesc('created_at');
    }

    public function onStatusChanged(int|string $recordId, string $status, array $fromOrderedIds, array $toOrderedIds): void
    {
        $record = $this->getEloquentQuery()->find($recordId);

        if (! $record) {
            return;
        }

        $stage = DealStage::find((int) $status);

        if (! $stage) {
            return;
        }

        if (Deal::blocksRollbackToFirstStage($record->stage_id, $stage->id)) {
            Notification::make()
                ->title('Нельзя вернуть закрытую сделку в первую стадию')
                ->body('Сначала переведите «Выиграна»/«Проиграна» в промежуточную стадию воронки.')
                ->danger()
                ->send();

            return;
        }

        $record->moveToStage($stage, auth()->id());
    }
}
