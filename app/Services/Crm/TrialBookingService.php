<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Support\Facades\DB;

/**
 * Free-intro + paid-trial CRM state on Deal (H3247). Rank 4: never grants
 * access, never writes payments, never writes course_group / group_user /
 * LessonAccessGrant.
 */
class TrialBookingService
{
    public function bookFree(string $email, Schedule $schedule, array $attrs = []): ?Deal
    {
        if (! config('features.crm_trial_booking')) {
            return null;
        }

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $first = DealStage::firstStage();
        if ($first === null) {
            return null;
        }

        return DB::transaction(function () use ($email, $schedule, $attrs, $first): Deal {
            $lead = Lead::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($lead === null) {
                $lead = Lead::create([
                    'email' => $email,
                    'name' => $attrs['name'] ?? null,
                    'contact' => $email,
                    'status' => Lead::firstStageKey(),
                ]);
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            $existing = Deal::query()
                ->trial()
                ->open()
                ->where('lead_id', $lead->id)
                ->where('schedule_id', $schedule->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $deal = Deal::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'course_id' => $schedule->course_id,
                'schedule_id' => $schedule->id,
                'amount' => 0,
                'currency' => 'RUB',
                'stage_id' => $first->id,
                'kind' => Deal::KIND_TRIAL,
                'trial_source' => Deal::TRIAL_SOURCE_FREE,
                'trial_outcome' => Deal::TRIAL_OUTCOME_BOOKED,
            ]);

            DealTransition::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $first->id,
                'user_id' => null,
                'created_at' => now(),
            ]);

            return $deal;
        });
    }

    /**
     * Tag an existing Deal as the paid-trial SKU. Does not open a second Deal.
     * Does not close the Deal as won — attendance still owed.
     */
    public function tagPaidDeal(Deal $deal, Payment $payment): Deal
    {
        if (! config('features.crm_trial_booking') || ! $payment->isTrial()) {
            return $deal;
        }

        $scheduleId = $deal->schedule_id
            ?? $payment->course?->trial_schedule_id;

        $attrs = [
            'kind' => Deal::KIND_TRIAL,
            'trial_source' => Deal::TRIAL_SOURCE_PAID,
            'trial_outcome' => $deal->trial_outcome ?: Deal::TRIAL_OUTCOME_BOOKED,
        ];
        if ($scheduleId) {
            $attrs['schedule_id'] = $scheduleId;
        }
        if ($deal->course_id === null && $payment->course_id) {
            $attrs['course_id'] = $payment->course_id;
        }
        if ($deal->lead_id === null && $payment->lead_id) {
            $attrs['lead_id'] = $payment->lead_id;
        }
        if ($deal->user_id === null && $payment->user_id) {
            $attrs['user_id'] = $payment->user_id;
        }

        $deal->update($attrs);

        return $deal->refresh();
    }

    /**
     * Observer entry: find-or-create ONE Deal for a trial Payment, then tag it.
     * Trial SKUs are excluded from the H2102 course-sale shape, so without this
     * path a paid trial would have zero CRM state.
     */
    public function tagPaidPayment(Payment $payment): ?Deal
    {
        if (! config('features.crm_trial_booking') || ! $payment->isTrial()) {
            return null;
        }

        if (! in_array($payment->status, array_merge(Payment::PAID_STATUSES, ['pending']), true)) {
            return null;
        }

        $deal = $this->findDealForPayment($payment) ?? $this->openTrialDealForPayment($payment);

        return $deal === null ? null : $this->tagPaidDeal($deal, $payment);
    }

    public function applyOutcome(Deal $deal, string $outcome, ?User $actor = null): Deal
    {
        if (! config('features.crm_trial_booking')) {
            return $deal;
        }

        if (! in_array($outcome, Deal::TRIAL_OUTCOMES, true)) {
            return $deal;
        }

        $deal->update(['trial_outcome' => $outcome]);

        DealTransition::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $deal->stage_id,
            'to_stage_id' => $deal->stage_id,
            'user_id' => $actor?->id,
            'created_at' => now(),
        ]);

        return $deal->refresh();
    }

    public function reconcileAttendance(?int $dealId = null): int
    {
        if (! config('features.crm_trial_booking')) {
            return 0;
        }

        $grace = now()->subMinutes(15);
        $query = Deal::query()
            ->trial()
            ->open()
            ->whereNotNull('schedule_id')
            ->with(['schedule', 'user', 'lead']);

        if ($dealId !== null) {
            $query->whereKey($dealId);
        }

        $touched = 0;
        foreach ($query->get() as $deal) {
            $schedule = $deal->schedule;
            if ($schedule === null) {
                continue;
            }

            $end = $schedule->end
                ?? ($schedule->start?->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS));
            if ($end === null || $end->greaterThan($grace)) {
                continue;
            }

            if ($this->reconcileOne($deal)) {
                $touched++;
            }
        }

        return $touched;
    }

    public function openTrialSeatCount(Schedule $schedule): int
    {
        return Deal::query()
            ->trial()
            ->open()
            ->where('schedule_id', $schedule->id)
            ->count();
    }

    private function reconcileOne(Deal $deal): bool
    {
        if (in_array($deal->trial_outcome, [
            Deal::TRIAL_OUTCOME_ATTENDED,
            Deal::TRIAL_OUTCOME_NO_SHOW,
            Deal::TRIAL_OUTCOME_CONVERTED,
        ], true)) {
            return false;
        }

        $emails = array_values(array_filter([
            strtolower((string) ($deal->user?->email ?? '')),
            strtolower((string) ($deal->lead?->email ?? '')),
        ]));

        $matched = WebinarAttendance::query()
            ->where('schedule_id', $deal->schedule_id)
            ->where(function ($q) use ($deal, $emails): void {
                if ($deal->user_id) {
                    $q->orWhere('user_id', $deal->user_id);
                }
                foreach ($emails as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->get();

        $present = $matched->first(
            fn (WebinarAttendance $row): bool => ((int) $row->duration_seconds >= 60)
                || $row->joined_at !== null
        );

        if ($present !== null) {
            $deal->update(['trial_outcome' => Deal::TRIAL_OUTCOME_ATTENDED]);
            $this->ensureFollowUp($deal, 'дожим после пробника');

            return true;
        }

        // Unmatched Zoom email / empty report: leave booked. Never invent no_show.
        $this->ensureFollowUp($deal, 'подтвердить посещение');

        return true;
    }

    private function ensureFollowUp(Deal $deal, string $note): void
    {
        $exists = FollowUpTask::query()
            ->where('deal_id', $deal->id)
            ->open()
            ->where('note', $note)
            ->exists();

        if ($exists) {
            return;
        }

        FollowUpTask::create([
            'deal_id' => $deal->id,
            'assigned_to' => $deal->assigned_to,
            'type' => FollowUpTask::TYPE_MESSAGE,
            'note' => $note,
            'due_at' => now()->addDay(),
        ]);
    }

    private function findDealForPayment(Payment $payment): ?Deal
    {
        if ($payment->user_id || $payment->lead_id) {
            $open = Deal::query()->open();
            if ($payment->lead_id) {
                $open->where('lead_id', $payment->lead_id);
            } elseif ($payment->user_id) {
                $open->where('user_id', $payment->user_id);
            }
            if ($payment->course_id) {
                $open->where(function ($q) use ($payment): void {
                    $q->where('course_id', $payment->course_id)->orWhereNull('course_id');
                });
            }

            $found = (clone $open)->where('kind', Deal::KIND_TRIAL)->orderBy('id')->first()
                ?? (clone $open)->orderBy('id')->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function openTrialDealForPayment(Payment $payment): ?Deal
    {
        $first = DealStage::firstStage();
        if ($first === null) {
            return null;
        }

        $scheduleId = $payment->course?->trial_schedule_id;

        return DB::transaction(function () use ($payment, $first, $scheduleId): Deal {
            $deal = Deal::create([
                'lead_id' => $payment->lead_id,
                'user_id' => $payment->user_id,
                'course_id' => $payment->course_id,
                'schedule_id' => $scheduleId,
                'amount' => $payment->amount ?? 0,
                'currency' => 'RUB',
                'stage_id' => $first->id,
                'kind' => Deal::KIND_TRIAL,
                'trial_source' => Deal::TRIAL_SOURCE_PAID,
                'trial_outcome' => Deal::TRIAL_OUTCOME_BOOKED,
            ]);

            DealTransition::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $first->id,
                'user_id' => null,
                'created_at' => now(),
            ]);

            return $deal;
        });
    }
}
