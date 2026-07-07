<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PranaTransaction;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Реферальная программа: приглашённый студент привязывается к пригласившему по
 * коду, и когда приглашённый ВПЕРВЫЕ оплачивает курс — пригласившему начисляется
 * денежный кредит на `referral_credit` (зачитывается в следующую покупку).
 * Награда за приглашённого выдаётся ровно один раз.
 *
 * Раньше наградой была прана; теперь — реальный кредит (config('referral.credit_amount')).
 */
class ReferralService
{
    /** Reason старых прана-наград — оставлен для idempotency-гейта при миграции. */
    public const REWARD_REASON = 'referral';

    /**
     * Привязать нового студента к пригласившему по коду. Идемпотентно и безопасно:
     * нельзя привязать себя к себе, перезаписать существующего реферера или
     * указать несуществующий код.
     */
    public function attachReferrer(User $newUser, ?string $code): void
    {
        $code = trim((string) $code);
        if ($code === '' || filled($newUser->referred_by)) {
            return;
        }

        $referrer = User::where('referral_code', $code)->first();
        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        $newUser->forceFill(['referred_by' => $referrer->id])->save();
    }

    /**
     * Начислить пригласившему денежный кредит за первую оплату приглашённого.
     * Вызывается из PaymentObserver при переходе платежа в paid. Награда — ровно
     * один раз на каждого приглашённого студента.
     */
    public function rewardForPayment(Payment $payment): void
    {
        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        // Награда — только за ПЕРВУЮ РЕАЛЬНУЮ ОПЛАТУ КУРСА (config/referral.php:
        // «приглашённый ВПЕРВЫЕ оплачивает курс»). Не курсовые/бесплатные события
        // не должны сжигать разовую награду и минтить реальный кредит:
        //   - conditional «под обещание» (amount=0, is_conditional) — не покупка;
        //   - deposit (бронь) / trial (пробное) — не оплата курса;
        //   - Расход / salary_payout — бухгалтерские записи;
        //   - 0₽-заказ (100%-промо или полное покрытие праной+кредитом) — нет выручки;
        //   - платёж без course_id — системный.
        if (! $this->isQualifyingCoursePayment($payment)) {
            return;
        }

        $referred = $payment->user;
        if (! $referred || blank($referred->referred_by)) {
            return;
        }

        // ВАЖНО: не $referred->referrer — миграция 2026_07_07_120000 добавила
        // users.referrer (строка UTM-атрибуции), которая теперь затеняет
        // одноимённую BelongsTo-связь referrer() в magic-геттере Eloquent
        // (getAttribute() всегда предпочитает реальную колонку атрибуту-связи с
        // тем же именем). $referred->referrer молча возвращал null вместо
        // пригласившего — награда переставала начисляться вовсе.
        $referrer = User::find($referred->referred_by);
        if (! $referrer) {
            return;
        }

        if ($this->alreadyRewarded($referred)) {
            return;
        }

        $amount = (float) config('referral.credit_amount', 500);
        if ($amount <= 0) {
            return;
        }

        try {
            DB::transaction(function () use ($referrer, $referred, $payment, $amount): void {
                // unique(referred_id): гонка двух платежей не задвоит награду.
                $reward = ReferralReward::firstOrCreate(
                    ['referred_id' => $referred->id],
                    ['referrer_id' => $referrer->id, 'payment_id' => $payment->id, 'amount' => $amount],
                );

                if ($reward->wasRecentlyCreated) {
                    $referrer->increment('referral_credit', $amount);
                }
            });
        } catch (\Throwable $e) {
            // Награда не должна ломать оплату.
            Log::warning('ReferralService::rewardForPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Квалифицирует ли платёж на реферальную награду: реальная (не conditional),
     * с положительной суммой, по курсу, и НЕ deposit/trial/Расход/salary_payout.
     */
    private function isQualifyingCoursePayment(Payment $payment): bool
    {
        return ! $payment->is_conditional
            && (float) $payment->amount > 0
            && filled($payment->course_id)
            && ! $payment->isDeposit()
            && ! $payment->isTrial()
            && ! $payment->isExpense()
            && ! $payment->isSalaryPayout();
    }

    /**
     * Реверс реферальной награды, когда платёж, за который она была начислена,
     * откатывается (paid → failed/canceled). Зеркалит rewardForPayment: снимает
     * начисленный рефереру кредит (не ниже нуля — часть могла быть уже потрачена)
     * и удаляет строку ReferralReward, чтобы unique(referred_id) больше не блокировал
     * законную будущую награду за этого приглашённого. Идемпотентно: после удаления
     * повторный вызов ничего не находит.
     */
    public function reverseRewardForPayment(Payment $payment): void
    {
        $reward = ReferralReward::where('payment_id', $payment->id)->first();
        if (! $reward) {
            return;
        }

        try {
            DB::transaction(function () use ($reward): void {
                $referrer = User::find($reward->referrer_id);
                if ($referrer) {
                    $newCredit = max(0.0, (float) $referrer->referral_credit - (float) $reward->amount);
                    $referrer->forceFill(['referral_credit' => $newCredit])->save();
                }

                $reward->delete();
            });
        } catch (\Throwable $e) {
            Log::warning('ReferralService::reverseRewardForPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Уже награждён за этого приглашённого? Учитываем и новые денежные награды, и
     * старые прана-награды — чтобы при переходе на кредит не наградить дважды тех,
     * кого уже наградили праной.
     */
    private function alreadyRewarded(User $referred): bool
    {
        if (ReferralReward::where('referred_id', $referred->id)->exists()) {
            return true;
        }

        return PranaTransaction::query()
            ->where('reason', self::REWARD_REASON)
            ->where('source_type', $referred->getMorphClass())
            ->where('source_id', $referred->getKey())
            ->exists();
    }
}
