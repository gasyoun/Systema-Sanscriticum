<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\LeadResource;
use App\Filament\Resources\UserResource;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;
use App\Services\WorkQueueReport;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «Моя работа сегодня» — операторский кокпит (H221). Единый домашний экран
 * нетех-ассистента: четыре бакета сегодняшней работы ({@see WorkQueueReport}) с
 * действием в один клик у каждой строки. За флагом crm_cockpit — пока OFF.
 */
class WorkQueue extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Моя работа сегодня';

    protected static ?string $title = 'Моя работа сегодня';

    protected static ?string $slug = 'work-queue';

    protected static ?int $navigationSort = -100;

    protected static string $view = 'filament.pages.work-queue';

    public static function canAccess(): bool
    {
        return config('features.crm_cockpit') && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    private ?WorkQueueReport $reportMemo = null;

    private function report(): WorkQueueReport
    {
        return $this->reportMemo ??= app(WorkQueueReport::class);
    }

    /** @return Collection<int, \App\Models\Lead> */
    public function getLeadsProperty(): Collection
    {
        return $this->report()->leadsToContact();
    }

    /** @return Collection<int, PaymentPromise> */
    public function getPromisesProperty(): Collection
    {
        return $this->report()->overduePromises();
    }

    /** @return Collection<int, User> */
    public function getStuckProperty(): Collection
    {
        return $this->report()->stuckStudents();
    }

    /** @return Collection<int, array{conversation:\App\Models\SupportConversation, waiting_since:\Illuminate\Support\Carbon}> */
    public function getSupportProperty(): Collection
    {
        return $this->report()->unansweredSupport();
    }

    /** URL-хелперы для действий «открыть». */
    public function leadUrl(int $leadId): string
    {
        return LeadResource::getUrl('edit', ['record' => $leadId]);
    }

    public function studentUrl(int $userId): string
    {
        return UserResource::getUrl('edit', ['record' => $userId]);
    }

    public function dialogUrl(int $userId): string
    {
        return Helpdesk::getUrl().'?user_id='.$userId;
    }

    /**
     * Один клик по обещанию: напоминание об оплате дефолтным текстом по
     * доступным каналам (переиспользуем {@see DebtorReminderDispatcher}).
     */
    public function remindPromise(int $promiseId): void
    {
        $promise = PaymentPromise::with('user')->find($promiseId);
        if (! $promise || ! $promise->user) {
            Notification::make()->title('Обещание не найдено')->danger()->send();

            return;
        }

        $user = $promise->user;
        $ok = app(DebtorReminderDispatcher::class)->send(
            $user,
            (int) $promise->course_id,
            null,
            DebtorReminderDispatcher::DEFAULT_TEXT,
            DebtorReminderDispatcher::DEFAULT_SUBJECT,
            ! empty($user->telegram_id),
            ! empty($user->vk_id),
            filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false,
        );

        if (! $ok) {
            Notification::make()->title('Нет каналов для отправки')->danger()->send();

            return;
        }

        Notification::make()->title('Напоминание поставлено в очередь')->success()->send();
    }
}
