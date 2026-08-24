<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H3330 — per-wave readout for the sequential warm-tail A/B
 * (MONETIZATION_PLAN_2026H2 §3, MG ruling 22-08-2026).
 *
 * One row per wave (flagship coupon vs membership offer), over ALL marathon
 * enrolments — unpaid and paid alike (the wave assignment is a property of
 * the enrolment moment, not of payment status):
 *
 *   participants        enrolments in the wave
 *   tripwire            paid the ₽500 «Консультация» track (paid_at set)
 *   day1/day2           finished the Day-1 / Day-2 tap quizzes (reachability)
 *   purchasers          ≥1 real revenue payment within the 13-day post-start
 *                       window (day0 .. day0+warm_tail_days+3)
 *   revenue             summed amount of those payments
 *   rev/participant     revenue ÷ participants
 *
 * Read-only: no writes anywhere. Zero counts pre-launch are normal — this is
 * the measurement-first contract, not a sales push.
 */
final class MarathonWarmtailAbReport extends Command
{
    /** Mirrors TeacherSalaryService::NON_REVENUE_TARIFFS — never revenue. */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    /**
     * Pre-offer payments, never counted as offer-branch purchases:
     * reservations/trials, and the marathon tripwire itself (₽500
     * «Консультация») — the tripwire is already reported via paid_at.
     */
    private const PRE_PURCHASE_TARIFFS = ['deposit', 'trial', 'marathon_paid'];

    protected $signature = 'marathon:warmtail-ab-report';

    protected $description = 'Отчёт A/B тёплого хвоста марафона по последовательным волнам (флагман vs членство)';

    public function handle(): int
    {
        $cutoff = trim((string) config('marathon.warm_tail_wave2_from', ''));
        $windowDays = (int) config('marathon.warm_tail_days', 13) + 3;

        $this->info('Warm-tail wave A/B report — '.now()->format('Y-m-d H:i'));
        $this->line('wave2 cutoff: '.($cutoff !== '' ? $cutoff : '(unset — everyone on flagship)'));

        // One query per side of the join; the marathon population is small,
        // so grouping happens in memory against one payment fetch.
        $paymentsByUser = $this->revenuePaymentsByUser();
        $usersByLead = User::query()->whereNotNull('lead_id')->pluck('id', 'lead_id');

        $rows = [];
        $totals = ['participants' => 0, 'tripwire' => 0, 'purchasers' => 0, 'revenue' => 0.0];

        foreach ([MarathonEnrollment::WAVE_FLAGSHIP, MarathonEnrollment::WAVE_MEMBERSHIP] as $wave) {
            $enrollments = MarathonEnrollment::with('lead')
                ->get()
                ->filter(fn (MarathonEnrollment $e) => $e->warmTailWave() === $wave);

            $purchasers = 0;
            $revenue = 0.0;

            foreach ($enrollments as $enrollment) {
                $userId = $usersByLead[$enrollment->lead_id] ?? null;
                if ($userId === null) {
                    continue;
                }

                $windowEnd = $enrollment->day0_started_at->copy()->startOfDay()->addDays($windowDays)->endOfDay();
                $inWindow = $paymentsByUser->get($userId, collect())
                    ->first(fn (Payment $p) => Carbon::instance($p->first_paid_at)->between(
                        $enrollment->day0_started_at, $windowEnd,
                    ));

                if ($inWindow !== null) {
                    $purchasers++;
                    $revenue += (float) $inWindow->amount;
                }
            }

            $participants = $enrollments->count();
            $rows[] = [
                'wave' => $wave,
                'participants' => $participants,
                'tripwire' => $enrollments->whereNotNull('paid_at')->count(),
                'day1' => $enrollments->whereNotNull('day1_completed_at')->count(),
                'day2' => $enrollments->whereNotNull('day2_completed_at')->count(),
                'purchasers' => $purchasers,
                'revenue, ₽' => number_format($revenue, 0, ',', ' '),
                'rev/participant, ₽' => $participants > 0 ? number_format($revenue / $participants, 0, ',', ' ') : '—',
            ];

            $totals['participants'] += $participants;
            $totals['tripwire'] += $enrollments->whereNotNull('paid_at')->count();
            $totals['purchasers'] += $purchasers;
            $totals['revenue'] += $revenue;
        }

        $this->table(
            ['wave', 'participants', 'tripwire', 'day1', 'day2', 'purchasers', 'revenue, ₽', 'rev/participant, ₽'],
            $rows,
        );

        $this->line(sprintf(
            'total: %d participant(s), %d tripwire, %d purchaser(s), revenue %s ₽ (window: day0 +%d d)',
            $totals['participants'], $totals['tripwire'], $totals['purchasers'],
            number_format($totals['revenue'], 0, ',', ' '), $windowDays,
        ));
        $this->line('Sequential waves — direction only, not a clean effect (calendar confound); MONETIZATION_PLAN_2026H2 §3.');

        return self::SUCCESS;
    }

    /**
     * Real revenue payments grouped per user: paid statuses, non-conditional,
     * excluding non-revenue and pre-purchase tariffs (canonical sets from
     * TeacherSalaryService / ReceivablesGovernanceService).
     *
     * @return Collection<int, Collection<int, Payment>>
     */
    private function revenuePaymentsByUser(): Collection
    {
        return Payment::query()
            ->paid()
            ->real()
            ->whereNotIn('tariff', array_merge(self::NON_REVENUE_TARIFFS, self::PRE_PURCHASE_TARIFFS))
            ->whereNotNull('first_paid_at')
            ->orderBy('first_paid_at')
            ->get()
            ->groupBy('user_id');
    }
}
