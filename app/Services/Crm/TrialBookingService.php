<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Course;
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
 * H3247 — trial Deal as funnel object (free book + paid tag + Zoom reconcile).
 *
 * Rank 4: never grants access, never writes payments, never writes
 * course_group / group_user / LessonAccessGrant.
 */
final class TrialBookingService
{
    public function bookFree(string $email, Schedule $schedule, array $attrs = []): ?Deal
    {
        if (! config('features.crm_trial_booking')) {
            return null;
        }

        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return null;
        }

        $first = DealStage::firstStage();
        if ($first === null) {
            return null;
        }

        return DB::transaction(function () use ($email, $schedule, $attrs, $first): Deal {
            $lead = $this->findOrCreateLead($email, $attrs);
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            $existing = Deal::query()
                ->open()
                ->trial()
                ->where('lead_id', $lead->id)
                ->where('schedule_id', $schedule->id)
                ->orderBy('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $deal = Deal::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'course_id' => $this->courseIdForSchedule($schedule),
                'amount' => 0,
                'currency' => 'RUB',
                'stage_id' => $first->id,
                'kind' => Deal::KIND_TRIAL,
                'schedule_id' => $schedule->id,
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

    public function tagPaidDeal(Deal $deal, Payment $payment): Deal
    {
        if (! config('features.crm_trial_booking')) {
            return $deal;
        }
        if (! $payment->isTrial()) {
            return $deal;
        }

        $scheduleId = $deal->schedule_id
            ?? $this->trialScheduleIdForCourse($payment->course_id);

        $deal->update([
            'kind' => Deal::KIND_TRIAL,
            'trial_source' => Deal::TRIAL_SOURCE_PAID,
            'trial_outcome' => $deal->trial_outcome ?: Deal::TRIAL_OUTCOME_BOOKED,
            'schedule_id' => $scheduleId,
            'course_id' => $deal->course_id ?? $payment->course_id,
            'lead_id' => $deal->lead_id ?? $payment->lead_id,
            'user_id' => $deal->user_id ?? $payment->user_id,
            'amount' => $deal->amount ?: ($payment->amount ?? 0),
        ]);

        return $deal->fresh() ?? $deal;
    }

    public function applyOutcome(Deal $deal, string $outcome, ?User $actor = null): Deal
    {
        if (! config('features.crm_trial_booking')) {
            return $deal;
        }
        if (! in_array($outcome, Deal::trialOutcomes(), true)) {
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

        return $deal->fresh() ?? $deal;
    }

    /**
     * Zoom reconcile for one open trial Deal whose schedule is past grace.
     *
     * @return array{outcome: string, task: bool}
     */
    public function reconcileDeal(Deal $deal): array
    {
        if (! config('features.crm_trial_booking')) {
            return ['outcome' => (string) $deal->trial_outcome, 'task' => false];
        }
        if ($deal->kind !== Deal::KIND_TRIAL) {
            return ['outcome' => (string) $deal->trial_outcome, 'task' => false];
        }
        if (in_array($deal->trial_outcome, [
            Deal::TRIAL_OUTCOME_ATTENDED,
            Deal::TRIAL_OUTCOME_NO_SHOW,
            Deal::TRIAL_OUTCOME_CONVERTED,
        ], true)) {
            return ['outcome' => (string) $deal->trial_outcome, 'task' => false];
        }

        $schedule = $deal->schedule;
        if ($schedule === null || ! $this->schedulePastGrace($schedule)) {
            return ['outcome' => (string) $deal->trial_outcome, 'task' => false];
        }

        $emails = $this->dealEmails($deal);
        $matched = $this->attendanceMatches($schedule->id, $emails, $deal->user_id);
        $zoomRan = WebinarAttendance::query()->where('schedule_id', $schedule->id)->exists();

        if ($matched) {
            $deal->update(['trial_outcome' => Deal::TRIAL_OUTCOME_ATTENDED]);
            $task = $this->openDraftTask($deal, 'дожим после пробника');

            return ['outcome' => Deal::TRIAL_OUTCOME_ATTENDED, 'task' => $task];
        }

        if ($zoomRan) {
            $deal->update(['trial_outcome' => Deal::TRIAL_OUTCOME_NO_SHOW]);
            $task = $this->openDraftTask($deal, 'дожим после пробника');

            return ['outcome' => Deal::TRIAL_OUTCOME_NO_SHOW, 'task' => $task];
        }

        $task = $this->openDraftTask($deal, 'подтвердить посещение');

        return ['outcome' => Deal::TRIAL_OUTCOME_BOOKED, 'task' => $task];
    }

    public function findOpenDealForPaidTrial(Payment $payment): ?Deal
    {
        $base = Deal::query()->open();

        if ($payment->lead_id) {
            $base->where('lead_id', $payment->lead_id);
        } elseif ($payment->user_id) {
            $base->where('user_id', $payment->user_id);
        } else {
            return null;
        }

        $scheduleId = $this->trialScheduleIdForCourse($payment->course_id);
        if ($scheduleId !== null) {
            $bySchedule = (clone $base)->where('schedule_id', $scheduleId)->orderBy('id')->first();
            if ($bySchedule !== null) {
                return $bySchedule;
            }
        }

        if ($payment->course_id) {
            $byCourse = (clone $base)->where('course_id', $payment->course_id)->orderBy('id')->first();
            if ($byCourse !== null) {
                return $byCourse;
            }
        }

        $trial = (clone $base)->trial()->orderBy('id')->first();
        if ($trial !== null) {
            return $trial;
        }

        return (clone $base)->orderBy('id')->first();
    }

    public function openPaidTrialShell(Payment $payment): ?Deal
    {
        $first = DealStage::firstStage();
        if ($first === null) {
            return null;
        }

        $deal = Deal::create([
            'lead_id' => $payment->lead_id,
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'amount' => $payment->amount ?? 0,
            'currency' => 'RUB',
            'stage_id' => $first->id,
            'kind' => Deal::KIND_TRIAL,
            'schedule_id' => $this->trialScheduleIdForCourse($payment->course_id),
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
    }

    private function findOrCreateLead(string $email, array $attrs): Lead
    {
        $existing = Lead::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing !== null) {
            return $existing;
        }

        $byContact = Lead::query()->whereRaw('LOWER(contact) = ?', [$email])->first();
        if ($byContact !== null) {
            if ($byContact->email === null) {
                $byContact->update(['email' => $email]);
            }

            return $byContact;
        }

        $name = trim((string) ($attrs['name'] ?? ''));

        return Lead::create([
            'name' => $name !== '' ? $name : $email,
            'contact' => $email,
            'email' => $email,
            'status' => Lead::firstStageKey(),
            'is_promo_agreed' => false,
        ]);
    }

    private function courseIdForSchedule(Schedule $schedule): ?int
    {
        if ($schedule->course_id) {
            return (int) $schedule->course_id;
        }

        $courseId = Course::query()
            ->where('trial_schedule_id', $schedule->id)
            ->value('id');

        return $courseId !== null ? (int) $courseId : null;
    }

    private function trialScheduleIdForCourse(?int $courseId): ?int
    {
        if ($courseId === null) {
            return null;
        }

        $id = Course::query()->whereKey($courseId)->value('trial_schedule_id');

        return $id !== null ? (int) $id : null;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function schedulePastGrace(Schedule $schedule): bool
    {
        $end = $schedule->end ?? ($schedule->start
            ? $schedule->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS)
            : null);
        if ($end === null) {
            return false;
        }

        return $end->copy()->addMinutes(15)->lte(now());
    }

    /** @return list<string> */
    private function dealEmails(Deal $deal): array
    {
        $out = [];
        if ($deal->user?->email) {
            $out[] = $this->normalizeEmail($deal->user->email);
        }
        if ($deal->lead?->email) {
            $out[] = $this->normalizeEmail($deal->lead->email);
        }
        if ($deal->lead?->contact) {
            $out[] = $this->normalizeEmail((string) $deal->lead->contact);
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** @param  list<string>  $emails */
    private function attendanceMatches(int $scheduleId, array $emails, ?int $userId): bool
    {
        $q = WebinarAttendance::query()->where('schedule_id', $scheduleId);
        if ($userId !== null && (clone $q)->where('user_id', $userId)->exists()) {
            return true;
        }
        foreach ($emails as $email) {
            if ((clone $q)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return true;
            }
        }

        return false;
    }

    private function openDraftTask(Deal $deal, string $note): bool
    {
        $exists = FollowUpTask::query()
            ->where('deal_id', $deal->id)
            ->open()
            ->where('note', $note)
            ->exists();
        if ($exists) {
            return false;
        }

        FollowUpTask::create([
            'deal_id' => $deal->id,
            'assigned_to' => $deal->assigned_to,
            'type' => FollowUpTask::TYPE_MESSAGE,
            'note' => $note,
            'due_at' => now()->addDay(),
        ]);

        return true;
    }
}
