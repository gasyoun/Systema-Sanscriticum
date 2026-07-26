<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Deal;
use App\Models\Lead;
use App\Support\RoleGate;
use App\Support\Roles;
use App\Support\UnifiedSalesStage;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

/**
 * СВОДНАЯ ДОСКА ПРОДАЖ (F9, H1658) — заявки и сделки в одних колонках.
 *
 * Рулинг 26-07-2026 по развилке F9 спеки §7: обе существующие доски
 * (LeadKanbanBoard, DealKanbanBoard) остаются нетронутыми, а общий слой стадий
 * строится АЛЬТЕРНАТИВНЫМ третьим представлением. Это не вариант «убрать доску
 * заявок» и не разрушительная форма варианта «свести стадии»: унификация живёт
 * только в слое представления, словарь колонок — App\Support\UnifiedSalesStage.
 * Ни одной миграции, ни одной записи в lead_stages/deal_stages.
 *
 * Перенос карточки пишет в ту сущность, которой карточка принадлежит: заявка —
 * leads.status напрямую (как LeadKanbanBoard), сделка — через
 * Deal::moveToStage(), чтобы журнал deal_transitions продолжал наполняться. Оба
 * гарда отката финальной стадии те же, что на одиночных досках.
 *
 * Объём выборки намеренно совпадает с одиночными досками (все заявки + все
 * сделки, без окна по дате): расхождение по видимому набору между досками
 * путало бы менеджера сильнее, чем длинные колонки «Выиграно»/«Проиграно».
 */
class UnifiedSalesBoard extends KanbanBoard
{
    public const KIND_LEAD = 'lead';

    public const KIND_DEAL = 'deal';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Продажи — общая доска';

    protected static ?string $title = 'Продажи (общая доска)';

    protected static ?string $slug = 'sales-board';

    /**
     * База требует инициализированное типизированное статическое свойство, но
     * на ЭТОЙ доске оно не источник записей: все его потребители из пакета
     * (records(), form(), onStatusChanged(), onSortChanged()) переопределены
     * ниже, а карточки собираются из ДВУХ моделей. Оставлено заполненным, чтобы
     * апгрейд пакета не свалился в TypeError на неинициализированном свойстве;
     * регрессия «доска снова читает только одну модель» ловится тестом
     * board_shows_cards_from_both_entities.
     */
    protected static string $model = Lead::class;

    protected static string $recordTitleAttribute = 'kanban_title';

    protected static string $recordView = 'filament.pages.unified-sales-board-record';

    public bool $disableEditModal = true;

    public static function canAccess(): bool
    {
        // Тот же флаг, что и у доски сделок: без сделок сводная доска
        // вырождается в копию доски заявок. Своего флага не заводим —
        // это та же поверхность GC-C1.
        return config('features.crm_pipeline_board')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.crm_pipeline_board')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    /** Идентификатор карточки в DOM: ключи двух сущностей пересекаются. */
    public static function cardId(Model $record): string
    {
        return static::kindOf($record).'-'.$record->getKey();
    }

    public static function kindOf(Model $record): string
    {
        return $record instanceof Lead ? self::KIND_LEAD : self::KIND_DEAL;
    }

    public static function kindLabel(Model $record): string
    {
        return $record instanceof Lead ? 'Заявка' : 'Сделка';
    }

    /** @return array{0: string, 1: int}|null */
    public static function parseCardId(string $cardId): ?array
    {
        if (! preg_match('/^(lead|deal)-(\d+)$/', $cardId, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    protected function statuses(): Collection
    {
        return collect(UnifiedSalesStage::columns())
            ->map(fn (string $title, string $id): array => ['id' => $id, 'title' => $title])
            ->values();
    }

    /** @return Collection<int, Model> */
    protected function records(): Collection
    {
        $leads = Lead::query()->orderByDesc('created_at')->get();
        $deals = Deal::query()->with(['user', 'lead', 'course'])->orderByDesc('created_at')->get();

        return $leads->concat($deals)->sortByDesc('created_at')->values();
    }

    /**
     * Соответствие стадий колонкам считается по одному разу на отрисовку, а не
     * на карточку: иначе каждая из сотен карточек била бы в lead_stages/
     * deal_stages своим запросом.
     */
    protected function getViewData(): array
    {
        $leadColumns = UnifiedSalesStage::leadStageColumns();
        $dealColumns = UnifiedSalesStage::dealStageColumns();

        $grouped = $this->records()->groupBy(
            static fn (Model $record): string => $record instanceof Lead
                ? UnifiedSalesStage::forLeadIn($leadColumns, $record->status)
                : UnifiedSalesStage::forDealIn($dealColumns, $record->stage_id),
        );

        return [
            'statuses' => $this->statuses()->map(function (array $status) use ($grouped): array {
                $status['records'] = $grouped->get($status['id'], collect())->all();

                return $status;
            }),
        ];
    }

    public function onStatusChanged(int|string $recordId, string $status, array $fromOrderedIds, array $toOrderedIds): void
    {
        if (! UnifiedSalesStage::isColumn($status)) {
            return;
        }

        $parsed = static::parseCardId((string) $recordId);

        if ($parsed === null) {
            return;
        }

        [$kind, $id] = $parsed;

        $kind === self::KIND_LEAD
            ? $this->moveLead($id, $status)
            : $this->moveDeal($id, $status);
    }

    /** Ручной порядок внутри колонки эта доска не хранит — сортировка по дате. */
    public function onSortChanged(int|string $recordId, string $status, array $orderedIds): void
    {
        //
    }

    /**
     * Модалка правки выключена, поэтому форма пустая: базовая реализация
     * привязала бы её к static::$model и правила бы сделки как заявки.
     */
    public function form(Form $form): Form
    {
        return $form->schema([])->statePath('editModalFormState');
    }

    private function moveLead(int $id, string $column): void
    {
        $lead = Lead::query()->find($id);

        if (! $lead) {
            return;
        }

        // Колонка объединяет несколько стадий: перенос внутри своей же колонки
        // не должен молча понижать «Квалифицирован» до «В работе».
        if (UnifiedSalesStage::forLead($lead->status) === $column) {
            return;
        }

        $target = UnifiedSalesStage::leadStatusFor($column);

        if ($target === null) {
            $this->notifyUnmappedColumn('заявок');

            return;
        }

        if (Lead::blocksRollbackToFirstStage((string) $lead->status, $target)) {
            Notification::make()
                ->title('Нельзя вернуть финальный статус в первую стадию')
                ->body('Сначала смените «Конверсию»/«Отказ» на промежуточную стадию в карточке лида.')
                ->danger()
                ->send();

            return;
        }

        $lead->update(['status' => $target]);
    }

    private function moveDeal(int $id, string $column): void
    {
        $deal = Deal::query()->find($id);

        if (! $deal) {
            return;
        }

        // То же правило, что и для заявок: перенос «Переговоры» → «В работе»
        // не откатывает сделку и не пишет лишнюю строку в deal_transitions.
        if (UnifiedSalesStage::forDeal($deal->stage_id) === $column) {
            return;
        }

        $stage = UnifiedSalesStage::dealStageFor($column);

        if (! $stage) {
            $this->notifyUnmappedColumn('сделок');

            return;
        }

        if (Deal::blocksRollbackToFirstStage($deal->stage_id, $stage->id)) {
            Notification::make()
                ->title('Нельзя вернуть закрытую сделку в первую стадию')
                ->body('Сначала переведите «Выиграна»/«Проиграна» в промежуточную стадию воронки.')
                ->danger()
                ->send();

            return;
        }

        $deal->moveToStage($stage, auth()->id());
    }

    private function notifyUnmappedColumn(string $funnel): void
    {
        Notification::make()
            ->title('В воронке '.$funnel.' нет стадии для этой колонки')
            ->body('Настройте стадии воронки — перенос карточки отменён.')
            ->danger()
            ->send();
    }
}
