<?php

declare(strict_types=1);

namespace App\Services\Crm\Lifecycle;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One rule's eligibility buckets. Dry-run and live send print the same shape.
 *
 * @phpstan-type BucketReason 'no_email'|'no_consent'|'outside_window'
 */
final class LifecycleCensus
{
    /**
     * @param  Collection<int, User>  $eligibleUsers
     * @param  list<int>  $suppressedUserIds
     * @param  list<int>  $recoveryUserIds
     * @param  list<int>  $deduplicatedUserIds
     * @param  array<int, string>  $excluded  user_id => reason
     */
    public function __construct(
        public readonly string $rule,
        public readonly int $version,
        public readonly Collection $eligibleUsers,
        public readonly array $excluded,
        public readonly array $suppressedUserIds,
        public readonly array $recoveryUserIds,
        public readonly array $deduplicatedUserIds,
        public readonly int $candidateCount,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'version' => $this->version,
            'candidates' => $this->candidateCount,
            'eligible' => $this->eligibleUsers->count(),
            'eligible_user_ids' => $this->eligibleUsers->pluck('id')->values()->all(),
            'excluded' => count($this->excluded),
            'excluded_reasons' => array_count_values(array_values($this->excluded)),
            'suppressed' => count($this->suppressedUserIds),
            'recovery' => count($this->recoveryUserIds),
            'deduplicated' => count($this->deduplicatedUserIds),
        ];
    }
}
