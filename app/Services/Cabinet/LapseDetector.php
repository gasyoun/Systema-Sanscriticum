<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\Course;
use App\Models\User;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use Illuminate\Support\Collection;

/**
 * Lapse detector (R29 bill §3.1 / H1572): expired access as a first-class state,
 * not a silent query filter. Powers «Истёкшие» shelf ribbons and home expired rows.
 *
 * A course is lapsed when the student has historical paid engagement but the
 * continuous paid-block chain has a gap (StudentDebtsService debt) — i.e. they
 * stopped renewing. Does NOT invent time-based lapses without debt evidence
 * (many recording purchases are open-ended).
 *
 * Read-only. Never grants or revokes access.
 */
final class LapseDetector
{
    public function __construct(
        private readonly StudentDebtsService $debts,
        private readonly DebtPaymentResolver $payResolver,
    ) {}

    /**
     * @return Collection<int, object{course_id: int, course: Course, reason: string, label: string, renewal_url: ?string, debt: ?object}>
     */
    public function forUser(User $user): Collection
    {
        $debts = $this->debts->forUser($user);
        if ($debts->isEmpty()) {
            return collect();
        }

        $out = collect();
        foreach ($debts as $debt) {
            $course = $debt->course ?? Course::query()->find($debt->course_id);
            if (! $course) {
                continue;
            }

            $opts = $this->payResolver->optionsFor($debt, $user);
            $renewalUrl = $this->firstRenewalUrl($opts);

            $out->push((object) [
                'course_id' => (int) $debt->course_id,
                'course' => $course,
                'reason' => 'unpaid_block_gap',
                'label' => $debt->debt_label
                    ?? ('требует продления · '.$course->title),
                'renewal_url' => $renewalUrl,
                'debt' => $debt,
            ]);
        }

        return $out->keyBy('course_id');
    }

    /**
     * True when the user ever paid for the course but no longer has continuous cover.
     */
    public function isLapsed(User $user, int $courseId): bool
    {
        return $this->forUser($user)->has($courseId);
    }

    /**
     * @param  array<string, mixed>|null  $opts
     */
    private function firstRenewalUrl(?array $opts): ?string
    {
        if (! is_array($opts)) {
            return null;
        }

        foreach (['renew', 'next', 'bundle'] as $key) {
            if (! empty($opts[$key]['url'])) {
                return (string) $opts[$key]['url'];
            }
        }

        if (! empty($opts['blocks'][0]['url'])) {
            return (string) $opts['blocks'][0]['url'];
        }

        return route('student.access');
    }
}
