<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Discipline\DisciplineScore;
use Illuminate\Support\Facades\DB;

/**
 * Скор платёжной дисциплины — надстройка над данными StudentDebtsService /
 * PaymentPromise, не дублирует их SQL (см. docs/discipline-score-spec.md).
 * Advisory-only: результат нигде не влияет на скидки/рассрочку/conditional
 * access — только контекст для менеджера/преподавателя.
 */
class DisciplineScoreService
{
    /**
     * Скор для студента — lifetime-метрика (с момента зачисления на каждый
     * курс), отдельная от 12-мес. окна `unreliable:recount`.
     */
    public function forUser(User $user): DisciplineScore
    {
        $enrollments = DB::table('course_user')
            ->where('user_id', $user->id)
            ->get(['course_id', 'joined_at_block']);

        $totalBlocks = 0;
        $onTimeBlocks = 0;
        $creditBlocks = 0;
        $silenceEvents = 0;

        foreach ($enrollments as $enrollment) {
            $courseId = (int) $enrollment->course_id;
            $floor = $enrollment->joined_at_block !== null ? (int) $enrollment->joined_at_block : 1;

            $blocks = CourseBlock::query()
                ->where('course_id', $courseId)
                ->where('number', '>=', $floor)
                ->whereNotNull('starts_at')
                ->where('starts_at', '<=', now())
                ->orderBy('number')
                ->get();

            if ($blocks->isEmpty()) {
                continue;
            }

            $realPayments = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->where('is_conditional', false)
                ->get(['start_block', 'end_block', 'created_at']);

            $conditionalPayments = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->where('is_conditional', true)
                ->get(['start_block', 'end_block']);

            $promiseCreatedDates = PaymentPromise::query()
                ->where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->pluck('created_at');

            foreach ($blocks as $block) {
                $totalBlocks++;
                $number = (int) $block->number;

                $coveringReal = $realPayments->filter(
                    fn (Payment $p): bool => DebtorsReport::paymentCovers($p->start_block, $p->end_block, $number)
                );

                $paidOnTime = $coveringReal->contains(
                    fn (Payment $p): bool => $p->created_at !== null
                        && $block->starts_at !== null
                        && $p->created_at->lte($block->starts_at)
                );
                if ($paidOnTime) {
                    $onTimeBlocks++;
                }

                $coveredByConditional = $conditionalPayments->contains(
                    fn (Payment $p): bool => DebtorsReport::paymentCovers($p->start_block, $p->end_block, $number)
                );
                if ($coveredByConditional) {
                    $creditBlocks++;
                }

                // Молчание: блок истёк, реальной оплатой не покрыт, и ни одного
                // обещания по курсу не было создано до истечения блока.
                $blockEnded = $block->ends_at !== null && $block->ends_at->lt(now());
                $isUnpaidDebt = $coveringReal->isEmpty();
                if ($blockEnded && $isUnpaidDebt) {
                    $anyPromiseBefore = $promiseCreatedDates->contains(
                        fn ($createdAt): bool => $createdAt !== null
                            && \Illuminate\Support\Carbon::parse($createdAt)->lte($block->ends_at)
                    );
                    if (! $anyPromiseBefore) {
                        $silenceEvents++;
                    }
                }
            }
        }

        $onTimeRate = $totalBlocks > 0 ? $onTimeBlocks / $totalBlocks : 1.0;
        $creditRate = $totalBlocks > 0 ? $creditBlocks / $totalBlocks : 0.0;
        $promiseKeepRate = $this->promiseKeepRate($user);

        return $this->compose($onTimeRate, $promiseKeepRate, $creditRate, $silenceEvents);
    }

    /**
     * Групповой скор — простое среднее forUser() по активным участникам
     * (без взвешивания по объёму блоков в Phase 1).
     *
     * @return array{score: ?int, tier: ?string, member_count: int}
     */
    public function forGroup(Group $group): array
    {
        $users = $group->activeUsers()->get();
        if ($users->isEmpty()) {
            return ['score' => null, 'tier' => null, 'member_count' => 0];
        }

        $scores = $users->map(fn (User $u): int => $this->forUser($u)->score);
        $mean = (int) round($scores->avg());

        return [
            'score' => $mean,
            'tier' => $this->tierFor($mean),
            'member_count' => $users->count(),
        ];
    }

    /**
     * Доля обещаний, выполненных `fulfilled` до/на `promised_at`, от всех
     * когда-либо созданных обещаний пользователя (включая отменённые —
     * денежная договорённость, которую отменили, тоже часть истории).
     */
    private function promiseKeepRate(User $user): float
    {
        $promises = PaymentPromise::query()->where('user_id', $user->id)->get();
        if ($promises->isEmpty()) {
            return 1.0;
        }

        $kept = $promises->filter(
            fn (PaymentPromise $p): bool => $p->status === PaymentPromise::STATUS_FULFILLED
                && $p->fulfilled_at !== null
                && $p->promised_at !== null
                && $p->fulfilled_at->toDateString() <= $p->promised_at->toDateString()
        )->count();

        return $kept / $promises->count();
    }

    private function compose(float $onTimeRate, float $promiseKeepRate, float $creditRate, int $silenceEvents): DisciplineScore
    {
        $weightOnTime = (float) config('discipline.weight_on_time');
        $weightPromise = (float) config('discipline.weight_promise');
        $weightCredit = (float) config('discipline.weight_credit');
        $silenceWeight = (float) config('discipline.silence_weight_per_event');
        $silenceCap = (float) config('discipline.silence_cap');

        $silencePenalty = min($silenceEvents * $silenceWeight, $silenceCap);

        $score = 100.0
            - (1 - $onTimeRate) * $weightOnTime
            - (1 - $promiseKeepRate) * $weightPromise
            - $creditRate * $weightCredit
            - $silencePenalty;

        $score = (int) round(max(0.0, min(100.0, $score)));

        return new DisciplineScore($score, $this->tierFor($score), [
            'on_time_rate' => $onTimeRate,
            'promise_keep_rate' => $promiseKeepRate,
            'credit_reliance_rate' => $creditRate,
            'silence_events' => $silenceEvents,
        ]);
    }

    private function tierFor(int $score): string
    {
        return match (true) {
            $score >= (int) config('discipline.good_threshold') => DisciplineScore::TIER_GOOD,
            $score >= (int) config('discipline.watch_threshold') => DisciplineScore::TIER_WATCH,
            default => DisciplineScore::TIER_POOR,
        };
    }
}
