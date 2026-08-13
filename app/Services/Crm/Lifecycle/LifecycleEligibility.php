<?php

declare(strict_types=1);

namespace App\Services\Crm\Lifecycle;

use App\Models\ActivityEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Course;
use App\Models\Payment;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * CRM Wave 2 (H2484) — one eligibility query per rule for dry-run AND live send.
 *
 * Qualifying Payment denominator is the conversion.php excluded-tariff set
 * (same as OrderPaymentConversionService / FunnelTelemetry). Recovery users
 * never land in `eligible`. Already-sent rule+version pairs are `deduplicated`.
 */
final class LifecycleEligibility
{
    public const RULE_UNCOMPLETED_CHECKOUT = 'uncompleted_checkout';

    public const RULE_MISSING_FIRST_CABINET = 'missing_first_cabinet_action';

    public const RULE_NEXT_PRODUCT = 'next_product_eligible';

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            self::RULE_UNCOMPLETED_CHECKOUT,
            self::RULE_MISSING_FIRST_CABINET,
            self::RULE_NEXT_PRODUCT,
        ];
    }

    public function __construct(
        private readonly RecoveryStateResolver $recovery,
    ) {}

    public function version(string $rule): int
    {
        return (int) $this->ruleConfig($rule)['version'];
    }

    public function evaluate(string $rule): LifecycleCensus
    {
        $this->assertRule($rule);
        $version = $this->version($rule);
        $candidateIds = $this->candidateUserIds($rule);
        $users = $candidateIds === []
            ? collect()
            : User::query()->whereIn('id', $candidateIds)->get()->keyBy('id');

        $recoveryIds = $this->recovery
            ->recoveringUserIds($candidateIds)
            ->all();
        $recoverySet = array_fill_keys($recoveryIds, true);

        $suppressedEmails = $this->suppressedEmailSet(
            $users->pluck('email')->filter()->map(fn ($e) => mb_strtolower((string) $e))->all()
        );
        $alreadySent = $this->alreadySentUserIds($rule, $version, $candidateIds);

        $eligible = collect();
        $excluded = [];
        $suppressed = [];
        $recovery = [];
        $deduplicated = [];

        foreach ($candidateIds as $userId) {
            /** @var User|null $user */
            $user = $users->get($userId);
            if ($user === null) {
                $excluded[$userId] = 'no_email';

                continue;
            }

            $email = mb_strtolower((string) $user->email);
            if ($email === '') {
                $excluded[$userId] = 'no_email';

                continue;
            }
            if (! $user->wants_email_announcements) {
                $excluded[$userId] = 'no_consent';

                continue;
            }
            if (isset($suppressedEmails[$email])) {
                $suppressed[] = $userId;

                continue;
            }
            if (isset($recoverySet[$userId])) {
                $recovery[] = $userId;

                continue;
            }
            if (isset($alreadySent[$userId])) {
                $deduplicated[] = $userId;

                continue;
            }

            $eligible->push($user);
        }

        return new LifecycleCensus(
            rule: $rule,
            version: $version,
            eligibleUsers: $eligible->values(),
            excluded: $excluded,
            suppressedUserIds: $suppressed,
            recoveryUserIds: $recovery,
            deduplicatedUserIds: $deduplicated,
            candidateCount: count($candidateIds),
        );
    }

    /** Same collection CampaignSegmentResolver and the preparer consume. */
    public function eligibleUsers(string $rule): Collection
    {
        return $this->evaluate($rule)->eligibleUsers;
    }

    /** @return list<int> */
    public function candidateUserIds(string $rule): array
    {
        return match ($rule) {
            self::RULE_UNCOMPLETED_CHECKOUT => $this->uncompletedCheckoutUserIds(),
            self::RULE_MISSING_FIRST_CABINET => $this->missingFirstCabinetUserIds(),
            self::RULE_NEXT_PRODUCT => $this->nextProductUserIds(),
            default => throw new InvalidArgumentException('Unknown lifecycle rule: '.$rule),
        };
    }

    /** @return list<int> */
    private function uncompletedCheckoutUserIds(): array
    {
        $cfg = $this->ruleConfig(self::RULE_UNCOMPLETED_CHECKOUT);
        $minAge = Carbon::now()->subHours((int) $cfg['min_age_hours']);
        $since = Carbon::now()->subDays((int) $cfg['lookback_days']);

        $pending = $this->qualifyingPayments()
            ->where('status', 'pending')
            ->whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $minAge)
            ->get(['id', 'user_id', 'course_id', 'created_at']);

        $ids = [];
        foreach ($pending as $payment) {
            $laterPaid = $this->qualifyingPayments()
                ->where('user_id', $payment->user_id)
                ->where('course_id', $payment->course_id)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->where(function (Builder $q) use ($payment): void {
                    $q->where('created_at', '>', $payment->created_at)
                        ->orWhere('id', '>', $payment->id);
                })
                ->exists();
            if (! $laterPaid) {
                $ids[] = (int) $payment->user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    private function missingFirstCabinetUserIds(): array
    {
        $cfg = $this->ruleConfig(self::RULE_MISSING_FIRST_CABINET);
        $newest = Carbon::now()->subHours((int) $cfg['grace_hours']);
        $oldest = Carbon::now()->subDays((int) $cfg['lookback_days']);

        $paidUserIds = $this->qualifyingPayments()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $oldest)
            ->where('created_at', '<=', $newest)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($paidUserIds->isEmpty()) {
            return [];
        }

        $acted = ActivityEvent::query()
            ->whereIn('user_id', $paidUserIds)
            ->where('event_type', ActivityEvent::FIRST_CABINET_ACTION)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $actedSet = array_fill_keys($acted, true);

        return $paidUserIds
            ->reject(fn (int $id): bool => isset($actedSet[$id]))
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function nextProductUserIds(): array
    {
        $cfg = $this->ruleConfig(self::RULE_NEXT_PRODUCT);
        $since = Carbon::now()->subDays((int) $cfg['lookback_days']);

        $successors = Course::query()
            ->where('is_active', true)
            ->whereNotNull('predecessor_course_id')
            ->get(['id', 'predecessor_course_id']);

        if ($successors->isEmpty()) {
            return [];
        }

        $predecessorIds = $successors->pluck('predecessor_course_id')->unique()->all();
        $paidOnPredecessor = $this->qualifyingPayments()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereIn('course_id', $predecessorIds)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->get(['user_id', 'course_id']);

        if ($paidOnPredecessor->isEmpty()) {
            return [];
        }

        $acted = ActivityEvent::query()
            ->whereIn('user_id', $paidOnPredecessor->pluck('user_id')->unique())
            ->where('event_type', ActivityEvent::FIRST_CABINET_ACTION)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $actedSet = array_fill_keys($acted, true);

        $ids = [];
        foreach ($paidOnPredecessor as $payment) {
            $userId = (int) $payment->user_id;
            if (! isset($actedSet[$userId])) {
                continue;
            }
            $nextIds = $successors
                ->where('predecessor_course_id', (int) $payment->course_id)
                ->pluck('id')
                ->all();
            if ($nextIds === []) {
                continue;
            }
            $alreadyOnNext = $this->qualifyingPayments()
                ->where('user_id', $userId)
                ->whereIn('course_id', $nextIds)
                ->where(function (Builder $q): void {
                    $q->whereIn('status', Payment::PAID_STATUSES)
                        ->orWhere('status', 'pending');
                })
                ->exists();
            if (! $alreadyOnNext) {
                $ids[] = $userId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function qualifyingPayments(): Builder
    {
        $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        return Payment::query()
            ->where('is_conditional', false)
            ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('tariff', $excluded));
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function alreadySentUserIds(string $rule, int $version, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $since = Carbon::now()->subDays((int) config('crm_lifecycle.dedup_cooldown_days', 21));

        $campaignIds = Campaign::query()
            ->where('segment->type', 'lifecycle')
            ->where('segment->rule', $rule)
            ->where('segment->version', $version)
            ->pluck('id');

        if ($campaignIds->isEmpty()) {
            return [];
        }

        $sent = CampaignRecipient::query()
            ->whereIn('campaign_id', $campaignIds)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        return array_fill_keys($sent, true);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function suppressedEmailSet(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $found = SuppressedEmail::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->map(fn ($e) => mb_strtolower((string) $e))
            ->all();

        return array_fill_keys($found, true);
    }

    /** @return array<string, mixed> */
    public function ruleConfig(string $rule): array
    {
        $this->assertRule($rule);
        $cfg = config('crm_lifecycle.rules.'.$rule);
        if (! is_array($cfg)) {
            throw new InvalidArgumentException('Missing crm_lifecycle.rules.'.$rule);
        }

        return $cfg;
    }

    private function assertRule(string $rule): void
    {
        if (! in_array($rule, self::rules(), true)) {
            throw new InvalidArgumentException('Unknown lifecycle rule: '.$rule);
        }
    }
}
