<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StudentDebtsService
{
    private const PAID_STATUSES = ['paid', 'success'];

    /**
     * Долги конкретного студента: только курсы, по которым у него уже был
     * хотя бы один успешный платёж (тип «не продлил»). Бесплатный доступ
     * через групповое назначение задолженностью не считается.
     *
     * Возвращает коллекцию объектов вида:
     *   {course: Course, ref_block: CourseBlock, debt_block_numbers: list<int>, debt_label: string}
     */
    public function forUser(User $user): Collection
    {
        $paidCourseIds = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->pluck('course_id')
            ->unique()
            ->values();

        if ($paidCourseIds->isEmpty()) {
            return collect();
        }

        $courses = Course::query()
            ->whereIn('id', $paidCourseIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($courses->isEmpty()) {
            return collect();
        }

        $blocksByCourse = CourseBlock::query()
            ->whereIn('course_id', $courses->keys())
            ->orderBy('course_id')
            ->orderBy('number')
            ->get()
            ->groupBy('course_id');

        $paymentsByCourse = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->whereIn('course_id', $courses->keys())
            ->get(['course_id', 'start_block', 'end_block'])
            ->groupBy('course_id');

        $promises = PaymentPromise::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courses->keys())
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->orderByDesc('promised_at')
            ->get()
            ->keyBy('course_id');

        $now = Carbon::now();
        $today = $now->copy()->startOfDay();

        $result = collect();
        foreach ($courses as $courseId => $course) {
            $blocks = $blocksByCourse->get($courseId, collect());
            $refBlock = $this->referenceBlock($blocks, $now, $today);
            if (! $refBlock instanceof CourseBlock) {
                continue;
            }

            $payments = $paymentsByCourse->get($courseId, collect());

            // Курс считаем долговым только если текущий (reference) блок не
            // покрыт ни одним paid-платежом — зеркально логике admin-отчёта,
            // чтобы «доплатить за блоки до прихода на курс» не попадало в долги.
            $refCovered = false;
            foreach ($payments as $p) {
                if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, (int) $refBlock->number)) {
                    $refCovered = true;
                    break;
                }
            }
            if ($refCovered) {
                continue;
            }

            $debtNumbers = $this->debtBlockNumbers($blocks, (int) $refBlock->number, $payments);
            if (empty($debtNumbers)) {
                continue;
            }

            $promise = $promises->get((int) $courseId);
            $report = app(DebtorsReport::class);
            $debtAmount = $report->computeDebtAmount($user, (int) $courseId, $debtNumbers);

            $result->push((object) [
                'course' => $course,
                'course_id' => (int) $courseId,
                'ref_block' => $refBlock,
                'debt_block_numbers' => $debtNumbers,
                'debt_label' => DebtorsReport::formatBlockRanges($debtNumbers),
                'debt_amount' => $debtAmount['amount'],
                'debt_amount_approximate' => $debtAmount['missing_tariffs'] > 0,
                'promise' => $promise,
                'promise_active' => $promise !== null && ! $promise->isOverdue(),
                'promise_overdue' => $promise !== null && $promise->isOverdue(),
            ]);
        }

        return $result;
    }

    /**
     * @param  Collection<int, CourseBlock>  $blocks
     */
    private function referenceBlock(Collection $blocks, Carbon $now, Carbon $today): ?CourseBlock
    {
        $manual = $blocks->firstWhere('is_current', true);
        if ($manual !== null) {
            return $manual;
        }

        $byDate = $blocks->first(fn (CourseBlock $b) => $b->starts_at !== null
            && $b->ends_at !== null
            && $b->starts_at->lte($now)
            && $b->ends_at->gte($today)
        );
        if ($byDate !== null) {
            return $byDate;
        }

        return $blocks
            ->filter(fn (CourseBlock $b) => $b->starts_at !== null && $b->starts_at->gt($now))
            ->sortBy('starts_at')
            ->first();
    }

    /**
     * @param  Collection<int, CourseBlock>  $blocks
     * @param  Collection<int, Payment>  $payments
     * @return list<int>
     */
    private function debtBlockNumbers(Collection $blocks, int $refNumber, Collection $payments): array
    {
        $debt = [];
        foreach ($blocks as $block) {
            $n = (int) $block->number;
            if ($n > $refNumber) {
                continue;
            }
            $covered = false;
            foreach ($payments as $p) {
                if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $n)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $debt[] = $n;
            }
        }

        return $debt;
    }
}
