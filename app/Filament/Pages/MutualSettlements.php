<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\MutualSettlement;
use App\Models\User;
use App\Services\MutualSettlementService;
use App\Support\RoleGate;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * «Взаимозачёт» (H1730, шаг B4).
 *
 * Сверка двух цифр по одному человеку, который одновременно ученик и
 * преподаватель: сколько он заплатил школе и сколько ему начислено. Обе суммы
 * с расшифровкой, зачёт, направление и остаток; действие «Зафиксировать»
 * замораживает числа в акт {@see MutualSettlement}.
 *
 * ГЕЙТ: RoleGate::accounting() — тот же, что стоит на странице «Зарплаты»
 * ({@see TeacherSalaries::canAccess()}), а не выбранный по догадке между
 * finance() и accounting(). Это money-поверхность на выплатной стороне, и
 * обычный админ сюда не проходит, как и на зарплаты.
 *
 * Страница НИЧЕГО не считает сама — вся арифметика в
 * {@see MutualSettlementService}, чтобы её можно было проверить тестом и
 * повторить командой settlement:preview.
 */
class MutualSettlements extends Page implements HasForms
{
    use InteractsWithForms;

    /** Ключ кэша бейджа «пора пересмотреть» (B6). */
    public const BADGE_CACHE_KEY = 'nav_badge.mutual_settlements';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 86;

    protected static ?string $navigationLabel = 'Взаимозачёт';

    protected static ?string $title = 'Взаимозачёт преподаватель-ученик';

    protected static ?string $slug = 'mutual-settlements';

    protected static string $view = 'filament.pages.mutual-settlements';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return RoleGate::accounting();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::accounting();
    }

    /**
     * Жёлтый бейдж = число зафиксированных актов, по которым живое начисление
     * перевесило оплаты (B6). Условие пересмотра, названное MG, живёт здесь, а
     * не в чьей-то памяти.
     *
     * Читаем из кэша, как ProfitFunds/DelegationKpi: синхронный пересчёт на
     * каждой загрузке админки уже ронял панель в 500 на тяжёлом снимке.
     */
    public static function getNavigationBadge(): ?string
    {
        return Cache::get(self::BADGE_CACHE_KEY);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Кого сверяем')
                    ->description('Человек, который одновременно платит школе как ученик и получает от неё гонорар как преподаватель. '
                        .'В списке — только пользователи с проставленным teacher_id: без этой связки взаимозачёт не имеет смысла.')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\Select::make('user_id')
                                ->label('Человек')
                                ->options(fn (): array => User::query()
                                    ->whereNotNull('teacher_id')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required()
                                ->live()
                                ->helperText('Пусто? Значит ни у кого не проставлен users.teacher_id — связку заводит админ в карточке пользователя.'),

                            Forms\Components\Select::make('course_id')
                                ->label('Курс (на чей блок фиксируем условия)')
                                ->options(fn (): array => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                                ->searchable()
                                ->live()
                                ->helperText('Необязательно. Если выбрать курс и блок, период подставится из дат блока.'),
                        ]),

                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\Select::make('block_number')
                                ->label('Блок / поток')
                                ->options(fn (Forms\Get $get): array => $get('course_id')
                                    ? Course::find($get('course_id'))?->blocks()
                                        ->pluck('number', 'number')
                                        ->all() ?? []
                                    : [])
                                ->live(),

                            Forms\Components\DatePicker::make('period_from')
                                ->label('Период с')
                                ->native(false)
                                ->live(),

                            Forms\Components\DatePicker::make('period_to')
                                ->label('Период по')
                                ->native(false)
                                ->live(),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [$this->fixAction()];
    }

    /**
     * «Зафиксировать» — единственное действие страницы, которое пишет. С
     * подтверждением и полем примечания: подписываем цифру, а не пересчитываем.
     */
    private function fixAction(): Actions\Action
    {
        return Actions\Action::make('fix')
            ->label('Зафиксировать акт')
            ->icon('heroicon-o-lock-closed')
            ->color('primary')
            ->visible(fn (): bool => filled($this->data['user_id'] ?? null) && $this->preview() !== null)
            ->requiresConfirmation()
            ->modalHeading('Зафиксировать взаимозачёт')
            ->modalDescription('Суммы будут заморожены в акте: дальше они читаются из строки и не пересчитываются. '
                .'Прежний зафиксированный акт того же периода уйдёт в «Заменён».')
            ->form([
                Forms\Components\Textarea::make('note')
                    ->label('Примечание')
                    ->rows(3)
                    ->helperText('Свободный текст: на чём сошлись, кто согласовал.'),
            ])
            ->action(function (array $data): void {
                $user = User::find($this->data['user_id'] ?? null);
                if (! $user instanceof User) {
                    return;
                }

                try {
                    $settlement = app(MutualSettlementService::class)->fix(
                        $user,
                        ($this->data['course_id'] ?? null) ? (int) $this->data['course_id'] : null,
                        ($this->data['block_number'] ?? null) ? (int) $this->data['block_number'] : null,
                        $this->data['period_from'] ?? null,
                        $this->data['period_to'] ?? null,
                        auth()->id(),
                        $data['note'] ?? null,
                    );
                } catch (\DomainException $e) {
                    Notification::make()->title('Не удалось зафиксировать')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Акт зафиксирован')
                    ->body('К зачёту '.number_format((float) $settlement->offset_amount, 2, '.', ' ').' ₽. '
                        .'Он появится строкой зачёта при расчёте выплаты этому преподавателю.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Живая сверка по состоянию формы — то, что рисует вьюха. Возвращает null,
     * пока человек не выбран или инвариант «один человек» не выполнен.
     *
     * @return array<string, mixed>|null
     */
    public function preview(): ?array
    {
        $user = User::find($this->data['user_id'] ?? null);
        if (! $user instanceof User) {
            return null;
        }

        try {
            return app(MutualSettlementService::class)->preview(
                $user,
                ($this->data['course_id'] ?? null) ? (int) $this->data['course_id'] : null,
                ($this->data['block_number'] ?? null) ? (int) $this->data['block_number'] : null,
                $this->data['period_from'] ?? null,
                $this->data['period_to'] ?? null,
            );
        } catch (\DomainException) {
            // Гард «один человек» — вьюха покажет причину, страница не падает.
            return null;
        }
    }

    /** Текст ошибки инварианта для вьюхи (когда preview() вернул null). */
    public function previewError(): ?string
    {
        $user = User::find($this->data['user_id'] ?? null);
        if (! $user instanceof User) {
            return null;
        }

        try {
            app(MutualSettlementService::class)->assertSamePerson($user);

            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Прежние акты выбранного человека — история со статусами.
     *
     * @return Collection<int, MutualSettlement>
     */
    public function history(): Collection
    {
        $userId = $this->data['user_id'] ?? null;
        if (! $userId) {
            return collect();
        }

        return MutualSettlement::query()
            ->where('user_id', $userId)
            ->with(['course:id,title', 'payout:id,paid_at'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Пересчитываем бейдж, пока страница и так открыта на money-контуре —
        // фоновой команды под это заводить не стали (актов единицы).
        $count = app(MutualSettlementService::class)->needsReviewCount();
        Cache::put(self::BADGE_CACHE_KEY, $count > 0 ? (string) $count : null, now()->addMinutes(30));

        return [];
    }
}
