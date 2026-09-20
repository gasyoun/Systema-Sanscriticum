<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\MarathonEnrollment;
use App\Models\Payment;
use Carbon\CarbonImmutable;

/** Read-only acquisition cohort; never substitutes order creation for payment time. */
final class BeginnerPilotReport
{
    public function build(CarbonImmutable $from, int $days, array $mainCourseIds = [], ?float $supportMinutes = null): array
    {
        $end = $from->addDays($days);
        $until = $end->min(CarbonImmutable::now());
        $inside = fn ($date) => $date !== null && $date >= $from && $date < $until;
        $payments = Payment::query()->real()->where('amount', '>', 0)
            ->whereNull('refund_of_payment_id')
            ->whereNotIn('tariff', ['Расход', 'salary_payout', 'deposit', 'trial', 'donation'])
            ->with(['audits', 'lead', 'user.lead'])->get();
        $known = $payments->filter(fn (Payment $p) => $p->first_paid_at !== null);
        $window = $known->filter(fn (Payment $p) => $inside($p->first_paid_at));
        $groups = $payments->whereNotNull('user_id')->groupBy('user_id');
        $cohorts = ['first_time' => [], 'returning' => [], 'history_unknown' => []];
        $sources = ['observed' => 0, 'inferred' => 0, 'unknown' => 0];
        $marathon = [];
        $continued = [];
        $engaged = [];
        $started = [];
        foreach ($window->whereNotNull('user_id')->groupBy('user_id') as $userId => $orders) {
            $history = $groups[$userId];
            $undated = $history->contains(function (Payment $p) {
                if ($p->first_paid_at !== null) {
                    return false;
                }
                if (in_array($p->status, Payment::PAID_STATUSES, true)) {
                    return true;
                }

                return $p->audits->contains(function ($audit) {
                    $status = $audit->getAttribute('changes')['status'] ?? null;

                    return count(array_intersect((array) $status, Payment::PAID_STATUSES)) > 0;
                });
            });
            $earliest = $history->whereNotNull('first_paid_at')->sortBy('first_paid_at')->first();
            $kind = $earliest->first_paid_at < $from ? 'returning' : ($undated ? 'history_unknown' : 'first_time');
            $cohorts[$kind][] = $userId;
            if ($kind !== 'first_time') {
                continue;
            }
            $lead = $earliest->lead ?? $earliest->user?->lead;
            $provenance = trim((string) $lead?->utm_source) !== '' || trim((string) $lead?->source) !== ''
                ? 'observed' : (trim((string) $lead?->inferred_source) !== '' ? 'inferred' : 'unknown');
            $sources[$provenance]++;
            $intro = $orders->where('tariff', 'marathon_paid')->sortBy('first_paid_at')->first();
            if (! $intro) {
                continue;
            }
            $marathon[] = $userId;
            if ($mainCourseIds !== [] && $orders->contains(fn (Payment $p) => in_array((int) $p->course_id, $mainCourseIds, true)
                && $p->tariff !== 'marathon_paid' && $p->first_paid_at > $intro->first_paid_at)) {
                $continued[] = $userId;
            }
            $leadIds = $orders->pluck('lead_id')->push($earliest->user?->lead_id)->filter()->unique();
            if (MarathonEnrollment::query()->whereIn('lead_id', $leadIds)
                ->where('day1_started_at', '>=', $intro->first_paid_at)
                ->where('day1_started_at', '<', $until)->exists()) {
                $started[] = $userId;
            }
            if (MarathonEnrollment::query()->whereIn('lead_id', $leadIds)
                ->where('day1_engaged_at', '>=', $intro->first_paid_at)
                ->where('day1_engaged_at', '<', $until)->exists()) {
                $engaged[] = $userId;
            }
        }
        $refunds = Payment::query()->whereNotNull('refund_of_payment_id')
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)->get(['amount', 'refund_of_payment_id']);

        return [
            'window' => ['from_inclusive' => $from->toIso8601String(), 'end_exclusive' => $end->toIso8601String(), 'observed_until_exclusive' => $until->toIso8601String(), 'timezone' => $from->timezoneName, 'complete' => $end <= CarbonImmutable::now()],
            'buyers' => array_map('count', $cohorts),
            'first_time_source_provenance' => $sources,
            'first_time_source_coverage' => count($cohorts['first_time']) ? round(($sources['observed'] + $sources['inferred']) / count($cohorts['first_time']), 4) : null,
            'marathon_buyers' => ['all' => $window->where('tariff', 'marathon_paid')->whereNotNull('user_id')->pluck('user_id')->unique()->count(), 'first_time' => count($marathon)],
            'first_time_marathon_day1_quiz_completed' => count($engaged),
            'first_task_starts' => count($started),
            'support_minutes' => $supportMinutes,
            'support_minutes_provenance' => $supportMinutes === null ? 'unavailable' : 'manual',
            'main_course_ids' => $mainCourseIds,
            'first_time_marathon_main_course_buyers' => $mainCourseIds === [] ? null : count($continued),
            'reconciliation' => [
                'purchase_rows_with_paid_timestamp' => $window->count(),
                'purchase_rows_currently_paid' => $window->whereIn('status', Payment::PAID_STATUSES)->count(),
                'currently_paid_amount_rub' => round((float) $window->whereIn('status', Payment::PAID_STATUSES)->sum('amount'), 2),
                'rows_without_user' => $window->whereNull('user_id')->count(),
                'currently_paid_rows_missing_paid_timestamp' => $payments->whereNull('first_paid_at')->whereIn('status', Payment::PAID_STATUSES)->count(),
                'linked_refund_rows_recorded_in_window' => $refunds->count(),
                'linked_refund_amount_rub' => round((float) $refunds->sum(fn ($p) => abs((float) $p->amount)), 2),
            ],
            'limitations' => [
                'First-time means first known positive real purchase in retained payment history, not first-ever across external or deleted records. Deposit, trial and donation tariffs excluded.',
                'Undated paid history prevents first-time classification; payment creation dates are never substituted. Missing timestamps count covers all retained history.',
                'Buyers deduplicate by user ID; duplicate accounts cannot be merged. Row and amount totals retain duplicate payment records for reconciliation, not bank-confirmed net revenue.',
                'Observed source means recorded UTM or manual source; inferred remains separate. Current lead attribution may have been edited after purchase.',
                'Linked refunds use ledger creation time, sum absolute row amounts, and include refunds for purchases outside the window. Unlinked refunds are not identifiable.',
                'Day-1 starts count first server-rendered task pages after paid introduction, not delivery or quiz completion; historical starts before instrumentation are unavailable and not backfilled. Support minutes are manually supplied for this window, not automatically tracked.',
                'Continuation counts only explicit main-course IDs after introduction within this window. Omitted IDs yield null; later conversions need a later follow-up.',
            ],
        ];
    }
}
