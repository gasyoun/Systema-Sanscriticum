<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-only composition of one customer's sales / support / access / learning
 * surface. Every fact keeps its canonical owner — this object never persists.
 */
final class Customer360Snapshot
{
    public const KIND_USER = 'user';

    public const KIND_LEAD = 'lead';

    public const ACTION_FOLLOW_UP_OVERDUE = 'follow_up_overdue';

    public const ACTION_RECOVERY_PROMISE = 'recovery_promise';

    public const ACTION_SUPPORT_OPEN = 'support_open';

    public const ACTION_DEAL_NEEDS_CONTACT = 'deal_needs_contact';

    public const ACTION_LEAD_CONTACT = 'lead_contact';

    public const ACTION_LEARNING_NUDGE = 'learning_nudge';

    public const ACTION_REPEAT_PURCHASE = 'repeat_purchase';

    public const ACTION_NONE = 'none';

    /**
     * @param  Collection<int, Lead>  $leads
     * @param  Collection<int, Deal>  $deals
     * @param  Collection<int, FollowUpTask>  $followUps
     * @param  list<array<string, mixed>>  $timeline
     * @param  array<string, mixed>|null  $pipeline
     * @param  array<string, mixed>|null  $nextAction
     * @param  array<string, mixed>|null  $support
     * @param  array<string, mixed>|null  $accessPayment
     * @param  array<string, mixed>|null  $learning
     * @param  array<string, mixed>|null  $attribution
     */
    public function __construct(
        public readonly string $subjectKind,
        public readonly int $subjectId,
        public readonly string $displayName,
        public readonly ?User $user,
        public readonly Collection $leads,
        public readonly Collection $deals,
        public readonly Collection $followUps,
        public readonly ?array $pipeline,
        public readonly ?array $nextAction,
        public readonly ?array $support,
        public readonly ?array $accessPayment,
        public readonly ?array $learning,
        public readonly ?array $attribution,
        public readonly array $timeline,
    ) {}

    public function nextActionCode(): string
    {
        return (string) ($this->nextAction['code'] ?? self::ACTION_NONE);
    }

    public function primaryDeal(): ?Deal
    {
        return $this->deals->first(fn (Deal $deal): bool => $deal->closed_at === null)
            ?? $this->deals->first();
    }

    public function primaryLead(): ?Lead
    {
        return $this->leads->first();
    }
}
