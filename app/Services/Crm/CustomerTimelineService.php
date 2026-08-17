<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PartnerConversion;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\SupportConversationTopicService;
use App\Support\Telephony\CallEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * CRM Wave 1 (H2483) — compose a customer 360 from canonical owners.
 *
 * Reads Lead / Deal / FollowUpTask / Payment / PaymentPromise / SupportConversation
 * / ActivityEvent / PartnerConversion. Never copies a fact into a mirror table.
 * Stage writes go through {@see Deal::moveToStage()}; next-action writes go
 * through {@see FollowUpTask}. Payment and access rows are read-only.
 */
final class CustomerTimelineService
{
    public const QUERY_BUDGET = 30;

    public function __construct(
        private readonly SupportConversationTopicService $topics,
    ) {}

    public function forUser(User $user): Customer360Snapshot
    {
        return $this->compose(Customer360Snapshot::KIND_USER, $user->id, $user, null);
    }

    public function forLead(Lead $lead): Customer360Snapshot
    {
        return $this->compose(Customer360Snapshot::KIND_LEAD, $lead->id, null, $lead);
    }

    public function compose(string $kind, int $id, ?User $user, ?Lead $lead): Customer360Snapshot
    {
        [$users, $leads] = $this->resolveGraph($user, $lead);
        $userIds = $users->pluck('id')->filter()->values();
        $leadIds = $leads->pluck('id')->filter()->values();

        $deals = Deal::query()
            ->with(['stage', 'assignee', 'course', 'lead', 'sourcePayment'])
            ->where(function ($q) use ($userIds, $leadIds): void {
                if ($userIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', $userIds);
                }
                if ($leadIds->isNotEmpty()) {
                    $q->orWhereIn('lead_id', $leadIds);
                }
            })
            ->when($userIds->isEmpty() && $leadIds->isEmpty(), fn ($q) => $q->whereRaw('0 = 1'))
            ->latest('id')
            ->limit(20)
            ->get();

        $dealIds = $deals->pluck('id');
        $conversationQuery = SupportConversation::query()
            ->with(['topics' => fn ($q) => $q->orderByDesc('id')])
            ->where(function ($q) use ($userIds, $leadIds): void {
                if ($userIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', $userIds);
                }
                if ($leadIds->isNotEmpty()) {
                    $q->orWhereIn('lead_id', $leadIds);
                }
            })
            ->when($userIds->isEmpty() && $leadIds->isEmpty(), fn ($q) => $q->whereRaw('0 = 1'));

        $conversations = $conversationQuery
            ->latest('last_message_at')
            ->limit(15)
            ->get();

        $conversationIds = $conversations->pluck('id');

        $followUps = FollowUpTask::query()
            ->with(['deal', 'supportConversation', 'assignee'])
            ->where(function ($q) use ($dealIds, $conversationIds): void {
                if ($dealIds->isNotEmpty()) {
                    $q->orWhereIn('deal_id', $dealIds);
                }
                if ($conversationIds->isNotEmpty()) {
                    $q->orWhereIn('support_conversation_id', $conversationIds);
                }
            })
            ->when($dealIds->isEmpty() && $conversationIds->isEmpty(), fn ($q) => $q->whereRaw('0 = 1'))
            ->latest('due_at')
            ->limit(30)
            ->get();

        $payments = $userIds->isEmpty()
            ? collect()
            : Payment::query()
                ->with('course')
                ->whereIn('user_id', $userIds)
                ->latest('id')
                ->limit(20)
                ->get();

        $promises = $userIds->isEmpty()
            ? collect()
            : PaymentPromise::query()
                ->with('course')
                ->whereIn('user_id', $userIds)
                ->latest('id')
                ->limit(15)
                ->get();

        $activities = $userIds->isEmpty()
            ? collect()
            : ActivityEvent::query()
                ->whereIn('user_id', $userIds)
                ->latest('created_at')
                ->limit(25)
                ->get();

        // H2748 — CallEvent types (packet §4.2) are allow-listed telephony
        // telemetry stored as ActivityEvent (option 1, no call_events table).
        // Split them out so learning/next-action logic never treats a call as
        // a lesson-view signal, and the timeline can give them their own
        // CallEvent-branded row the same way FollowUpTask gets its own row.
        $callTypes = config('telephony.event_types', []);
        $callActivities = $activities->filter(
            fn (ActivityEvent $e): bool => in_array($e->event_type, $callTypes, true)
        )->values();
        $learningActivities = $activities->reject(
            fn (ActivityEvent $e): bool => in_array($e->event_type, $callTypes, true)
        )->values();

        $conversion = $userIds->isEmpty()
            ? null
            : PartnerConversion::query()
                ->with(['partner', 'payment'])
                ->whereIn('user_id', $userIds)
                ->latest('id')
                ->first();

        $subjectUser = $users->first();
        $subjectLead = $leads->first();
        $display = $subjectUser?->name
            ?: ($subjectLead?->kanban_title ?? ('Карточка #'.$id));

        $pipeline = $this->pipeline($deals, $leads);
        $support = $this->supportSummary($conversations);
        $access = $this->accessSummary($payments, $promises);
        $learning = $this->learningSummary($learningActivities);
        $attribution = $this->attributionSummary($leads, $conversion);
        $next = $this->recommendNextAction(
            $followUps,
            $deals,
            $leads,
            $conversations,
            $promises,
            $payments,
            $learningActivities,
        );
        $timeline = $this->timeline(
            $leads,
            $deals,
            $followUps,
            $payments,
            $promises,
            $conversations,
            $learningActivities,
            $callActivities,
            $conversion,
        );

        return new Customer360Snapshot(
            subjectKind: $kind,
            subjectId: $id,
            displayName: $display,
            user: $subjectUser,
            leads: $leads,
            deals: $deals,
            followUps: $followUps,
            pipeline: $pipeline,
            nextAction: $next,
            support: $support,
            accessPayment: $access,
            learning: $learning,
            attribution: $attribution,
            timeline: $timeline,
        );
    }

    /**
     * Next-action create goes through FollowUpTask only. Deal-attached tasks
     * keep CRM semantics; conversation-attached tasks keep support semantics.
     */
    public function createFollowUp(
        ?Deal $deal,
        ?SupportConversation $conversation,
        User $actor,
        string $type,
        Carbon $dueAt,
        ?string $note = null,
    ): FollowUpTask {
        if ($deal === null && $conversation === null) {
            throw new InvalidArgumentException('Follow-up needs a Deal or a support conversation.');
        }
        if (! array_key_exists($type, FollowUpTask::types())) {
            throw new InvalidArgumentException('Unknown follow-up type.');
        }

        return FollowUpTask::create([
            'deal_id' => $deal?->id,
            'support_conversation_id' => $conversation?->id,
            'assigned_to' => $actor->id,
            'type' => $type,
            'note' => $note,
            'due_at' => $dueAt,
        ]);
    }

    public function completeFollowUp(FollowUpTask $task): void
    {
        $task->markDone();
    }

    public function moveDealStage(Deal $deal, DealStage $stage, User $actor): void
    {
        if (Deal::blocksRollbackToFirstStage($deal->stage_id, $stage->id)) {
            throw new InvalidArgumentException('Cannot roll a final deal back to the first stage.');
        }

        $deal->moveToStage($stage, $actor->id);
    }

    /**
     * @return array{0: Collection<int, User>, 1: Collection<int, Lead>}
     */
    private function resolveGraph(?User $user, ?Lead $lead): array
    {
        $users = collect();
        $leads = collect();

        if ($user !== null) {
            $users->push($user);
        }
        if ($lead !== null) {
            $leads->push($lead);
        }

        if ($user !== null) {
            $matched = Lead::query()
                ->with(['assignee', 'stage'])
                ->where(function ($q) use ($user): void {
                    $email = $this->norm($user->email);
                    if ($email !== null) {
                        $q->orWhereRaw('LOWER(email) = ?', [$email]);
                    }
                    $phone = trim((string) $user->phone);
                    if ($phone !== '') {
                        $q->orWhere('contact', $phone);
                    }
                })
                ->limit(20)
                ->get();
            $leads = $leads->merge($matched);
        }

        if ($lead !== null && $user === null) {
            $matchedUsers = User::query()
                ->where(function ($q) use ($lead): void {
                    $email = $this->norm($lead->email) ?? $this->norm($lead->contact);
                    if ($email !== null && str_contains($email, '@')) {
                        $q->orWhereRaw('LOWER(email) = ?', [$email]);
                    }
                    $contact = trim((string) $lead->contact);
                    if ($contact !== '' && ! str_contains($contact, '@')) {
                        $q->orWhere('phone', $contact);
                    }
                })
                ->limit(5)
                ->get();
            $users = $users->merge($matchedUsers);

            if ($matchedUsers->isNotEmpty()) {
                $emails = $matchedUsers->pluck('email')->map(fn ($e) => $this->norm($e))->filter();
                $phones = $matchedUsers->pluck('phone')->filter();
                $extraLeads = Lead::query()
                    ->with(['assignee', 'stage'])
                    ->where(function ($q) use ($emails, $phones): void {
                        foreach ($emails as $email) {
                            $q->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                        if ($phones->isNotEmpty()) {
                            $q->orWhereIn('contact', $phones->all());
                        }
                    })
                    ->limit(20)
                    ->get();
                $leads = $leads->merge($extraLeads);
            }
        }

        if ($lead !== null && ! $lead->relationLoaded('assignee')) {
            $lead->load(['assignee', 'stage']);
        }

        return [
            $users->unique('id')->values(),
            $leads->unique('id')->values(),
        ];
    }

    /**
     * @param  Collection<int, Deal>  $deals
     * @param  Collection<int, Lead>  $leads
     * @return array<string, mixed>|null
     */
    private function pipeline(Collection $deals, Collection $leads): ?array
    {
        $openDeal = $deals->first(fn (Deal $deal): bool => $deal->closed_at === null);
        $deal = $openDeal ?? $deals->first();
        if ($deal !== null) {
            return [
                'owner' => 'Deal',
                'label' => $deal->stage?->name ?? ('стадия #'.$deal->stage_id),
                'stage_id' => $deal->stage_id,
                'assignee' => $deal->assignee?->name,
                'assignee_id' => $deal->assigned_to,
                'deal_id' => $deal->id,
                'url' => $this->dealBoardUrl(),
            ];
        }

        $lead = $leads->first();
        if ($lead !== null) {
            return [
                'owner' => 'Lead',
                'label' => $lead->stage?->label ?? $lead->status,
                'stage_key' => $lead->status,
                'assignee' => $lead->assignee?->name,
                'assignee_id' => $lead->assigned_to,
                'lead_id' => $lead->id,
                'url' => '/admin/leads/'.$lead->id.'/edit',
            ];
        }

        return null;
    }

    /**
     * @param  Collection<int, SupportConversation>  $conversations
     * @return array<string, mixed>|null
     */
    private function supportSummary(Collection $conversations): ?array
    {
        $open = $conversations->first(fn (SupportConversation $c): bool => $c->isOpen());
        $thread = $open ?? $conversations->first();
        if ($thread === null) {
            return null;
        }

        $topic = $thread->topics->first() ?? $this->topics->currentTopic($thread);

        return [
            'owner' => 'SupportConversation',
            'status' => $thread->status,
            'topic' => $topic?->category,
            'outcome' => $thread->status === SupportConversation::STATUS_CLOSED ? 'closed' : 'open',
            'conversation_id' => $thread->id,
            'url' => $this->helpdeskUrl(),
        ];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentPromise>  $promises
     * @return array<string, mixed>|null
     */
    private function accessSummary(Collection $payments, Collection $promises): ?array
    {
        if ($payments->isEmpty() && $promises->isEmpty()) {
            return null;
        }

        $paid = $payments->first(fn (Payment $p): bool => $p->status === 'paid');
        $pending = $payments->first(fn (Payment $p): bool => $p->status === 'pending');
        $overduePromise = $promises->first(function (PaymentPromise $p): bool {
            if ($p->status === PaymentPromise::STATUS_EXPIRED) {
                return true;
            }

            return $p->status === PaymentPromise::STATUS_ACTIVE
                && $p->promised_at !== null
                && $p->promised_at->lt(now()->startOfDay());
        });

        $state = 'none';
        if ($overduePromise !== null) {
            $state = 'promise_overdue';
        } elseif ($paid !== null) {
            $state = 'paid';
        } elseif ($pending !== null) {
            $state = 'pending';
        }

        $latest = $paid ?? $pending ?? $payments->first();

        return [
            'owner' => 'Payment',
            'state' => $state,
            'last_status' => $latest?->status,
            'last_amount' => $latest?->amount,
            'course' => $latest?->course?->title,
            'promise_status' => $overduePromise?->status,
            'payment_id' => $latest?->id,
            'url' => $latest !== null
                ? $this->paymentUrl($latest)
                : null,
        ];
    }

    /**
     * @param  Collection<int, ActivityEvent>  $activities
     * @return array<string, mixed>|null
     */
    private function learningSummary(Collection $activities): ?array
    {
        $event = $activities->first();
        if ($event === null) {
            return null;
        }

        return [
            'owner' => 'ActivityEvent',
            'event_type' => $event->event_type,
            'at' => optional($event->created_at)?->toIso8601String(),
            'url' => $event->user_id
                ? $this->userUrl($event->user_id)
                : null,
        ];
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @return array<string, mixed>|null
     */
    private function attributionSummary(Collection $leads, ?PartnerConversion $conversion): ?array
    {
        $lead = $leads->first(fn (Lead $l): bool => filled($l->utm_source) || filled($l->utm_campaign));
        if ($lead === null && $conversion === null) {
            return null;
        }

        return [
            'owner' => $conversion !== null ? 'PartnerConversion' : 'Lead',
            'utm_source' => $lead?->utm_source,
            'utm_medium' => $lead?->utm_medium,
            'utm_campaign' => $lead?->utm_campaign,
            'partner' => $conversion?->partner?->name,
            'conversion_status' => $conversion?->status,
            'url' => $lead !== null
                ? $this->leadUrl($lead)
                : null,
        ];
    }

    /**
     * @param  Collection<int, FollowUpTask>  $followUps
     * @param  Collection<int, Deal>  $deals
     * @param  Collection<int, Lead>  $leads
     * @param  Collection<int, SupportConversation>  $conversations
     * @param  Collection<int, PaymentPromise>  $promises
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, ActivityEvent>  $activities
     * @return array<string, mixed>
     */
    private function recommendNextAction(
        Collection $followUps,
        Collection $deals,
        Collection $leads,
        Collection $conversations,
        Collection $promises,
        Collection $payments,
        Collection $activities,
    ): array {
        $overdue = $followUps->first(fn (FollowUpTask $t): bool => $t->isOverdue());
        if ($overdue !== null) {
            return [
                'code' => Customer360Snapshot::ACTION_FOLLOW_UP_OVERDUE,
                'label' => 'Закрыть просроченную задачу: '.$overdue->label,
                'source' => $overdue->isSupportTask() ? 'FollowUpTask/support' : 'FollowUpTask/crm',
                'task_id' => $overdue->id,
                'url' => $overdue->isSupportTask() ? $this->helpdeskUrl() : $this->dealBoardUrl(),
            ];
        }

        $overduePromise = $promises->first(function (PaymentPromise $p): bool {
            if ($p->status === PaymentPromise::STATUS_EXPIRED) {
                return true;
            }

            return $p->status === PaymentPromise::STATUS_ACTIVE
                && $p->promised_at !== null
                && $p->promised_at->lt(now()->startOfDay());
        });
        if ($overduePromise !== null) {
            return [
                'code' => Customer360Snapshot::ACTION_RECOVERY_PROMISE,
                'label' => 'Восстановить оплату / обещание',
                'source' => 'PaymentPromise',
                'promise_id' => $overduePromise->id,
                'url' => $overduePromise->user_id
                    ? $this->userUrl($overduePromise->user_id)
                    : null,
            ];
        }

        $openSupport = $conversations->first(fn (SupportConversation $c): bool => $c->isOpen());
        if ($openSupport !== null) {
            $topic = $openSupport->topics->first();

            return [
                'code' => Customer360Snapshot::ACTION_SUPPORT_OPEN,
                'label' => $topic
                    ? 'Закрыть диалог поддержки (тема: '.$topic->category.')'
                    : 'Назначить тему и закрыть диалог поддержки',
                'source' => 'SupportConversation',
                'conversation_id' => $openSupport->id,
                'url' => $this->helpdeskUrl(),
            ];
        }

        $openDeal = $deals->first(fn (Deal $d): bool => $d->closed_at === null);
        $openDealTask = $openDeal === null
            ? null
            : $followUps->first(fn (FollowUpTask $t): bool => ! $t->isDone() && $t->deal_id === $openDeal->id);
        if ($openDeal !== null && $openDealTask === null) {
            return [
                'code' => Customer360Snapshot::ACTION_DEAL_NEEDS_CONTACT,
                'label' => 'Поставить следующий контакт по открытой сделке',
                'source' => 'Deal',
                'deal_id' => $openDeal->id,
                'url' => $this->dealBoardUrl(),
            ];
        }

        $unconverted = $leads->first(fn (Lead $l): bool => $l->converted_at === null
            && ! in_array($l->status, Lead::finalStatuses(), true));
        if ($unconverted !== null && $openDeal === null && $payments->where('status', 'paid')->isEmpty()) {
            return [
                'code' => Customer360Snapshot::ACTION_LEAD_CONTACT,
                'label' => 'Связаться с заявкой',
                'source' => 'Lead',
                'lead_id' => $unconverted->id,
                'url' => $this->leadUrl($unconverted),
            ];
        }

        $paid = $payments->first(fn (Payment $p): bool => $p->status === 'paid');
        $won = $deals->first(fn (Deal $d): bool => $d->closed_reason === Deal::REASON_WON);
        $hasLearning = $activities->contains(function (ActivityEvent $e): bool {
            return in_array($e->event_type, [
                ActivityEvent::TYPE_LESSON_OPEN,
                ActivityEvent::TYPE_LESSON_COMPLETE,
                ActivityEvent::FIRST_CABINET_ACTION,
                ActivityEvent::CABINET_HOME_VIEW,
                ActivityEvent::CABINET_CONTINUE_CLICK,
                ActivityEvent::VISUALDCS_PROGRESS_STARTED,
                ActivityEvent::VISUALDCS_PROGRESS_COMPLETED,
            ], true);
        });

        if (($paid !== null || $won !== null) && ! $hasLearning) {
            return [
                'code' => Customer360Snapshot::ACTION_LEARNING_NUDGE,
                'label' => 'Довести до первого действия в кабинете',
                'source' => 'ActivityEvent',
                'url' => $paid?->user_id
                    ? $this->userUrl($paid->user_id)
                    : null,
            ];
        }

        if (($paid !== null || $won !== null) && $hasLearning && $openDeal === null) {
            return [
                'code' => Customer360Snapshot::ACTION_REPEAT_PURCHASE,
                'label' => 'Предложить следующий курс',
                'source' => 'Deal',
                'url' => $this->dealBoardUrl(),
            ];
        }

        return [
            'code' => Customer360Snapshot::ACTION_NONE,
            'label' => 'Нет обязательного следующего шага',
            'source' => null,
            'url' => null,
        ];
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @param  Collection<int, Deal>  $deals
     * @param  Collection<int, FollowUpTask>  $followUps
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentPromise>  $promises
     * @param  Collection<int, SupportConversation>  $conversations
     * @param  Collection<int, ActivityEvent>  $learningActivities  non-call ActivityEvent rows
     * @param  Collection<int, ActivityEvent>  $callActivities  allow-listed CallEvent types (packet §4.2), branded separately
     * @return list<array<string, mixed>>
     */
    private function timeline(
        Collection $leads,
        Collection $deals,
        Collection $followUps,
        Collection $payments,
        Collection $promises,
        Collection $conversations,
        Collection $learningActivities,
        Collection $callActivities,
        ?PartnerConversion $conversion,
    ): array {
        $rows = collect();

        foreach ($leads as $lead) {
            $rows->push($this->row(
                $lead->created_at,
                'lead',
                'Lead',
                'Заявка: '.$lead->kanban_title,
                $this->leadUrl($lead),
                $lead->id,
            ));
        }
        foreach ($deals as $deal) {
            $rows->push($this->row(
                $deal->created_at,
                'deal',
                'Deal',
                'Сделка: '.$deal->kanban_title,
                $this->dealBoardUrl(),
                $deal->id,
            ));
        }
        foreach ($followUps as $task) {
            $rows->push($this->row(
                $task->created_at,
                $task->isSupportTask() ? 'follow_up_support' : 'follow_up_crm',
                $task->isSupportTask() ? 'FollowUpTask/support' : 'FollowUpTask/crm',
                $task->label,
                $task->isSupportTask() ? $this->helpdeskUrl() : $this->dealBoardUrl(),
                $task->id,
            ));
        }
        foreach ($payments as $payment) {
            $rows->push($this->row(
                $payment->created_at,
                'payment',
                'Payment',
                'Платёж '.$payment->status.' · '.($payment->course?->title ?? 'без курса'),
                $this->paymentUrl($payment),
                $payment->id,
            ));
        }
        foreach ($promises as $promise) {
            $rows->push($this->row(
                $promise->created_at,
                'promise',
                'PaymentPromise',
                'Обещание '.$promise->status,
                $this->userUrl($promise->user_id),
                $promise->id,
            ));
        }
        foreach ($conversations as $thread) {
            $topic = $thread->topics->first();
            $rows->push($this->row(
                $thread->created_at,
                'support',
                'SupportConversation',
                'Диалог '.$thread->status.($topic ? ' · '.$topic->category : ''),
                $this->helpdeskUrl(),
                $thread->id,
            ));
        }
        foreach ($learningActivities as $event) {
            $rows->push($this->row(
                $event->created_at,
                'learning',
                'ActivityEvent',
                'Событие '.$event->event_type,
                $event->user_id ? $this->userUrl($event->user_id) : null,
                $event->id,
            ));
        }
        foreach ($callActivities as $event) {
            $data = $event->event_data ?? [];
            $conversationId = $data['support_conversation_id'] ?? null;
            $rows->push($this->row(
                $event->created_at,
                'call',
                'CallEvent',
                $this->callEventLabel($event->event_type),
                $conversationId ? $this->helpdeskUrl() : ($event->user_id ? $this->userUrl($event->user_id) : null),
                $event->id,
            ));
        }
        if ($conversion !== null) {
            $rows->push($this->row(
                $conversion->created_at,
                'attribution',
                'PartnerConversion',
                'Партнёрская конверсия '.$conversion->status,
                null,
                $conversion->id,
            ));
        }

        return $rows
            ->filter(fn (array $row): bool => $row['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take(50)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?Carbon $at, string $kind, string $owner, string $title, ?string $url, int $id): array
    {
        return [
            'at' => $at,
            'kind' => $kind,
            'owner' => $owner,
            'title' => $title,
            'url' => $url,
            'id' => $id,
        ];
    }

    private function norm(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function userUrl(?int $id): ?string
    {
        return $id ? '/admin/users/'.$id : null;
    }

    private function leadUrl(Lead $lead): string
    {
        return '/admin/leads/'.$lead->id.'/edit';
    }

    private function paymentUrl(Payment $payment): string
    {
        return '/admin/payments/'.$payment->id.'/edit';
    }

    private function dealBoardUrl(): string
    {
        return '/admin/sales-board';
    }

    private function helpdeskUrl(): string
    {
        return '/admin/dialogs';
    }

    /**
     * H2748 — packet §4.2 allow-listed CallEvent types get a human label on
     * the timeline instead of the raw dotted event_type string.
     */
    private function callEventLabel(string $type): string
    {
        return match ($type) {
            CallEvent::REQUESTED => 'Запрошен обратный звонок',
            CallEvent::STARTED => 'Звонок начат',
            CallEvent::ANSWERED => 'Звонок отвечен',
            CallEvent::MISSED => 'Пропущенный звонок',
            CallEvent::ENDED => 'Звонок завершён',
            CallEvent::RECORDING_AVAILABLE => 'Доступна запись звонка (метаданные)',
            default => 'Звонок: '.$type,
        };
    }
}
