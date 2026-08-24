<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Crm\Customer360Snapshot;
use App\Services\Crm\CustomerTimelineService;
use App\Services\Crm\TrialBookingService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * CRM Wave 1 (H2483) — one customer workspace. Flag-gated OFF.
 * Money/access fields are displayed from Payment / PaymentPromise and are
 * not writable here.
 */
class Customer360 extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 65;

    protected static ?string $navigationLabel = 'Карточка 360';

    protected static ?string $title = 'Клиент 360';

    protected static ?string $slug = 'customer-360';

    protected static string $view = 'filament.pages.customer-360';

    public ?int $userId = null;

    public ?int $leadId = null;

    public string $lookup = '';

    public ?string $taskType = FollowUpTask::TYPE_CALL;

    public ?string $taskDue = null;

    public ?string $taskNote = null;

    public ?int $taskDealId = null;

    public ?int $moveStageId = null;

    public ?string $trialOutcome = null;

    public static function canAccess(): bool
    {
        return (bool) config('features.crm_customer_360')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function urlForUser(int $userId): string
    {
        return static::getUrl().'?user='.$userId;
    }

    public static function urlForLead(int $leadId): string
    {
        return static::getUrl().'?lead='.$leadId;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->userId = request()->integer('user') ?: null;
        $this->leadId = request()->integer('lead') ?: null;
        $this->taskDue = now()->addDay()->toDateString();
    }

    public function getSnapshot(): ?Customer360Snapshot
    {
        if ($this->userId) {
            $user = User::query()->find($this->userId);

            return $user ? app(CustomerTimelineService::class)->forUser($user) : null;
        }
        if ($this->leadId) {
            $lead = Lead::query()->find($this->leadId);

            return $lead ? app(CustomerTimelineService::class)->forLead($lead) : null;
        }

        return null;
    }

    public function search(): void
    {
        $q = trim($this->lookup);
        if ($q === '') {
            return;
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $q) ?: [],
            fn (string $t): bool => $t !== ''
        ));
        if ($tokens === []) {
            return;
        }

        $allTokensInName = function ($query) use ($tokens): void {
            $query->where(function ($name) use ($tokens): void {
                foreach ($tokens as $token) {
                    $name->where('name', 'like', '%'.$token.'%');
                }
            });
        };

        $user = User::query()
            ->where(function ($query) use ($q, $allTokensInName): void {
                $query->where('email', $q)
                    ->orWhere('phone', $q)
                    ->orWhere($allTokensInName);
            })
            ->first();
        if ($user !== null) {
            $this->redirect(self::urlForUser($user->id));

            return;
        }

        $lead = Lead::query()
            ->where(function ($query) use ($q, $allTokensInName): void {
                $query->where('email', $q)
                    ->orWhere('contact', $q)
                    ->orWhere($allTokensInName);
            })
            ->first();
        if ($lead !== null) {
            $this->redirect(self::urlForLead($lead->id));

            return;
        }

        Notification::make()
            ->title('Клиент не найден')
            ->body('Попробуйте фамилию одним словом, либо точный email / телефон.')
            ->warning()
            ->send();
    }

    public function completeTask(int $taskId): void
    {
        $snapshot = $this->getSnapshot();
        $task = $snapshot?->followUps->firstWhere('id', $taskId);
        if (! $task instanceof FollowUpTask) {
            Notification::make()->title('Задача не из этой карточки')->danger()->send();

            return;
        }

        app(CustomerTimelineService::class)->completeFollowUp($task);
        Notification::make()->title('Задача закрыта')->success()->send();
    }

    public function createTask(): void
    {
        $snapshot = $this->getSnapshot();
        if ($snapshot === null) {
            return;
        }

        $deal = $this->taskDealId
            ? $snapshot->deals->firstWhere('id', $this->taskDealId)
            : $snapshot->primaryDeal();
        $conversation = null;
        if (! $deal instanceof Deal) {
            $openSupport = $snapshot->support['conversation_id'] ?? null;
            $conversation = $openSupport
                ? SupportConversation::query()->find($openSupport)
                : null;
        }

        try {
            app(CustomerTimelineService::class)->createFollowUp(
                $deal instanceof Deal ? $deal : null,
                $conversation,
                auth()->user(),
                $this->taskType ?: FollowUpTask::TYPE_CALL,
                Carbon::parse($this->taskDue ?: now()->addDay()->toDateString()),
                $this->taskNote,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->taskNote = null;
        Notification::make()->title('Задача поставлена')->success()->send();
    }

    public function moveStage(): void
    {
        $snapshot = $this->getSnapshot();
        $deal = $snapshot?->primaryDeal();
        $stage = $this->moveStageId ? DealStage::query()->find($this->moveStageId) : null;
        if (! $deal instanceof Deal || ! $stage instanceof DealStage) {
            Notification::make()->title('Нет открытой сделки или стадии')->danger()->send();

            return;
        }

        try {
            app(CustomerTimelineService::class)->moveDealStage($deal, $stage, auth()->user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Стадия сделки обновлена')->success()->send();
    }

    public function applyTrialOutcome(): void
    {
        if (! config('features.crm_trial_booking')) {
            return;
        }

        $deal = $this->getSnapshot()?->primaryDeal();
        if (! $deal instanceof Deal || ! $deal->isTrial() || ! $this->trialOutcome) {
            Notification::make()->title('Нет пробной сделки')->danger()->send();

            return;
        }

        app(TrialBookingService::class)->applyOutcome($deal, $this->trialOutcome, auth()->user());
        Notification::make()->title('Исход пробника сохранён')->success()->send();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('lookup')
                ->label('Найти клиента')
                ->placeholder('email, телефон или имя')
                ->suffixAction(
                    Forms\Components\Actions\Action::make('go')
                        ->label('Найти')
                        ->action('search')
                ),
        ]);
    }
}
