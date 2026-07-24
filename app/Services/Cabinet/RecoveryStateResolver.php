<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Server-side recovery-state predicate (R29.2, H1481).
 *
 * Recovery ON only for real payment/access faults:
 *  1. declined_payment — recent failed/canceled/cancelled non-conditional
 *     payment with no later successful payment on the same course;
 *  2. expired_promise — PaymentPromise STATUS_EXPIRED (access-on-credit
 *     that was not paid).
 *
 * Explicitly NOT recovery (adversarial money-boundary):
 *  - bare pending (webhook-delayed bank redirect — over-inclusion trap);
 *  - plain «next block due» debt without a failed payment/expired promise
 *    (that stays a continue-band debt CTA, not a home-mode switch);
 *  - ancient failures after a later paid recovery on the same course.
 *
 * Does not authorize access, grant, or revoke anything — pure read predicate.
 * Money core stays authoritative; this only shapes cabinet UI mode + offer
 * suppression (Deal-bridge discipline: observe, never authorize).
 */
final class RecoveryStateResolver
{
    /** Lookback for declined payments — old history is not a live fault. */
    public const FAULT_LOOKBACK_DAYS = 14;

    /** Statuses that mean the bank/checkout rejected or cancelled the attempt. */
    public const DECLINED_STATUSES = ['failed', 'canceled', 'cancelled'];

    public function resolve(User $user): RecoveryState
    {
        $declined = $this->latestUnrecoveredDecline($user);
        if ($declined !== null) {
            return $this->fromDeclinedPayment($declined);
        }

        $expired = $this->latestExpiredPromise($user);
        if ($expired !== null) {
            return $this->fromExpiredPromise($expired);
        }

        return RecoveryState::normal();
    }

    private function latestUnrecoveredDecline(User $user): ?Payment
    {
        $since = Carbon::now()->subDays(self::FAULT_LOOKBACK_DAYS);

        $candidates = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::DECLINED_STATUSES)
            ->where('is_conditional', false)
            ->where('updated_at', '>=', $since)
            ->whereNotNull('course_id')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($candidates as $payment) {
            // Later successful payment on the same course clears the fault
            // (by time, or by id when timestamps collide).
            $recovered = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $payment->course_id)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->where('is_conditional', false)
                ->where(function ($q) use ($payment) {
                    $q->where('updated_at', '>', $payment->updated_at)
                        ->orWhere(function ($q2) use ($payment) {
                            $q2->where('updated_at', '>=', $payment->updated_at)
                                ->where('id', '>', $payment->id);
                        });
                })
                ->exists();

            if (! $recovered) {
                return $payment;
            }
        }

        return null;
    }

    private function latestExpiredPromise(User $user): ?PaymentPromise
    {
        return PaymentPromise::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentPromise::STATUS_EXPIRED)
            ->whereNotNull('course_id')
            ->orderByDesc('promised_at')
            ->first();
    }

    private function fromDeclinedPayment(Payment $payment): RecoveryState
    {
        $course = $this->courseFor($payment->course_id);
        $title = $course?->title ?? 'курс';
        $when = $payment->updated_at?->timezone(config('app.timezone'))->translatedFormat('d F') ?? 'недавно';

        return new RecoveryState(
            active: true,
            reason: RecoveryState::REASON_DECLINED_PAYMENT,
            headline: 'Не проходит оплата — доступ по «'.$title.'» требует действия',
            detail: 'Платёж от '.$when.' отклонён или отменён. Ваш прогресс и конспекты '
                .'на месте; уже оплаченные и бессрочные доступы продолжают работать. '
                .'Это единственное, что сегодня требует действия — предложений о новых '
                .'покупках в этом состоянии нет.',
            ctaUrl: route('student.access'),
            ctaLabel: 'Повторить оплату',
            faultPaymentIds: [(int) $payment->id],
            courseId: $payment->course_id ? (int) $payment->course_id : null,
            courseTitle: $course?->title,
        );
    }

    private function fromExpiredPromise(PaymentPromise $promise): RecoveryState
    {
        $course = $this->courseFor($promise->course_id);
        $title = $course?->title ?? 'курс';

        return new RecoveryState(
            active: true,
            reason: RecoveryState::REASON_EXPIRED_PROMISE,
            headline: 'Просрочена договорённость по оплате — «'.$title.'»',
            detail: 'Срок обещанного платежа прошёл. Уже оплаченные уроки и живые занятия '
                .'группы, к которым у вас есть доступ, продолжают работать. Сначала '
                .'закроем эту оплату — офферы скрыты, пока проблема не решена.',
            ctaUrl: route('student.access'),
            ctaLabel: 'Перейти к оплате',
            faultPromiseIds: [(int) $promise->id],
            courseId: $promise->course_id ? (int) $promise->course_id : null,
            courseTitle: $course?->title,
        );
    }

    private function courseFor(?int $courseId): ?Course
    {
        if (! $courseId) {
            return null;
        }

        return Course::query()->select(['id', 'title', 'slug'])->find($courseId);
    }
}
