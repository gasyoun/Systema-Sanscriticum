<?php

declare(strict_types=1);

namespace App\Support\MoneySli;

use App\Models\Course;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * H4672 — dedicated, isolated fixtures for the money-axis synthetic-pay
 * probe: one hidden/unsellable course+group and one synthetic user, never
 * touched by real checkout. Named per the H1946 convention (RESULTS_LOG.md
 * §218): name prefixed with the owning handoff id, email @example.invalid
 * (not @example.com — that's reserved for Faker) so OnboardingNotifier and
 * any human scanning users.csv can recognise it as synthetic on sight.
 *
 * course_id/group_id are real rows so the probe exercises the SAME
 * Payment::grantAccess() code path a real purchase does — is_visible=false
 * + is_active=false on the course keeps it out of the public catalog and
 * unsellable via direct link (stronger than the curator-gated-sale state
 * documented in CLAUDE.md § Money contour, which only requires one of the two).
 */
final class MoneySliFixture
{
    public function ensureUser(): User
    {
        $cfg = config('money_sli.fixture');

        return User::firstOrCreate(
            ['email' => $cfg['user_email']],
            [
                'name' => $cfg['user_name'],
                'password' => Hash::make(bin2hex(random_bytes(16))),
            ]
        );
    }

    public function ensureCourseWithGroup(): Course
    {
        $cfg = config('money_sli.fixture');

        $course = Course::firstOrCreate(
            ['slug' => $cfg['course_slug']],
            [
                'title' => $cfg['course_title'],
                'description' => 'H4672 money-axis SLI probe — internal fixture, never sold, never shown.',
                'is_visible' => false,
            ]
        );

        $group = Group::firstOrCreate(
            ['slug' => $cfg['group_slug']],
            [
                'name' => $cfg['group_name'],
                'status' => 'active',
            ]
        );

        if (! $course->groups()->where('groups.id', $group->id)->exists()) {
            $course->groups()->attach($group->id);
        }

        return $course->fresh(['groups']);
    }

    /**
     * Detach the probe group from the probe user (mocked-webhook equivalent
     * of "revoke access" — real refund N/A, amount is always 0).
     */
    public function revokeAccess(User $user, Group $group): void
    {
        $user->groups()->detach($group->id);
    }

    /**
     * Prune synthetic Payment rows older than fixture.retain_days so the
     * daily append doesn't grow the payments table forever. Read-only on
     * everything except this one user's own rows.
     */
    public function pruneOldPayments(User $user): int
    {
        $retainDays = max(1, (int) config('money_sli.fixture.retain_days', 30));
        $cutoff = now()->subDays($retainDays);

        return $user->payments()->where('created_at', '<', $cutoff)->delete();
    }
}
