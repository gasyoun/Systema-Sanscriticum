<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Catches a near-miss email at checkout signup — same local-part, a
 * typo'd domain (.con/.com, gmial/gmail, one dropped/extra char) — that
 * `User::normalizeEmail()` exact-match dedup does not, and would silently
 * pass as a genuinely new account.
 *
 * Read-only / advisory: never blocks signup, only surfaces candidates for
 * `CuratorNotifier::possibleDuplicateAccount()`. False positives (two real
 * people who happen to share a local-part on different providers) are
 * expected and cheap — a curator dismisses them; a missed real duplicate is
 * the expensive failure mode this exists to catch.
 */
class NearDuplicateEmailDetector
{
    private const MAX_DISTANCE = 2;

    /**
     * @return Collection<int, User>
     */
    public function findFor(User $newUser): Collection
    {
        $email = (string) $newUser->email;
        if (! str_contains($email, '@')) {
            return collect();
        }

        [$localPart] = explode('@', $email, 2);
        if ($localPart === '') {
            return collect();
        }

        return User::query()
            ->where('id', '!=', $newUser->id)
            ->where('email', 'like', $localPart.'@%')
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $candidate): bool => $this->isNearDuplicate($email, (string) $candidate->email))
            ->values();
    }

    private function isNearDuplicate(string $a, string $b): bool
    {
        if ($a === $b) {
            return false; // exact match is the existing dedup's job, not this one's.
        }

        return levenshtein($a, $b) <= self::MAX_DISTANCE;
    }
}
