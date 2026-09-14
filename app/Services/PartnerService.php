<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerConversion;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Партнёрская (агентская) программа. Клиент привязывается к партнёру по коду
 * (last-touch, users.partner_id); когда клиент ВПЕРВЫЕ реально оплачивает курс —
 * партнёру начисляется фиксированное денежное вознаграждение (partner_conversions,
 * статус accrued «к выплате»). Начисление — ровно один раз на клиента.
 *
 * Отличие от ReferralService (студент→студент, кредит на счёт): здесь
 * вознаграждение — реальная ВЫПЛАТА внешнему партнёру, а не кредит на покупку.
 * Вся логика — только при config('partner.enabled').
 */
class PartnerService
{
    /** Программа активна? Все точки входа сначала спрашивают это. */
    public function enabled(): bool
    {
        return (bool) config('partner.enabled', false);
    }

    /**
     * Привязать клиента к партнёру по коду. Идемпотентно и безопасно: только для
     * АКТИВНОГО партнёра, нельзя привязать партнёра к его же аккаунту, перезаписать
     * существующую привязку или указать несуществующий/чужой код.
     */
    public function attachPartner(User $client, ?string $code): void
    {
        if (! $this->enabled()) {
            return;
        }

        $code = trim((string) $code);
        if ($code === '' || filled($client->partner_id)) {
            return;
        }

        $partner = Partner::where('code', $code)->first();
        if (! $partner || ! $partner->isActive()) {
            return;
        }

        // Партнёр не может «привести» сам себя (свой же студенческий аккаунт).
        if ($partner->user_id !== null && (int) $partner->user_id === (int) $client->id) {
            return;
        }

        $client->forceFill(['partner_id' => $partner->id])->save();
    }

    /**
     * Начислить партнёру вознаграждение за первую реальную оплату курса
     * приведённым клиентом. Вызывается из PaymentObserver при переходе в paid.
     * Ровно один раз на клиента (unique user_id).
     */
    public function rewardForPayment(Payment $payment): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        // Только ПЕРВАЯ РЕАЛЬНАЯ ОПЛАТА КУРСА — тот же гейт, что и в студенческой
        // программе (не deposit/trial/Расход/salary_payout/conditional/0₽/без курса).
        if (! $this->isQualifyingCoursePayment($payment)) {
            return;
        }

        $client = $payment->user;
        if (! $client || blank($client->partner_id)) {
            return;
        }

        $partner = Partner::find($client->partner_id);
        if (! $partner || ! $partner->isActive()) {
            return;
        }

        if ($this->alreadyRewarded($client)) {
            return;
        }

        $amount = $partner->rewardOnPayment($payment);
        if ($amount <= 0) {
            return;
        }

        try {
            DB::transaction(function () use ($partner, $client, $payment, $amount): void {
                // unique(user_id): гонка двух платежей не задвоит вознаграждение.
                PartnerConversion::firstOrCreate(
                    ['user_id' => $client->id],
                    [
                        'partner_id' => $partner->id,
                        'payment_id' => $payment->id,
                        'reward_amount' => $amount,
                        'status' => PartnerConversion::STATUS_ACCRUED,
                    ],
                );
            });
        } catch (\Throwable $e) {
            // Начисление не должно ломать оплату.
            Log::warning('PartnerService::rewardForPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Реверс начисления, когда платёж откатывается (paid → failed/canceled).
     * Снимаем только ЕЩЁ НЕ ВЫПЛАЧЕННОЕ (accrued) вознаграждение и удаляем строку,
     * освобождая разовый слот (unique user_id). Если партнёру уже выплатили
     * (paid_out) — строку не трогаем: это уже свершившийся факт выплаты, его
     * разбирают вручную (не автоматически откатываем реальные деньги).
     */
    public function reverseRewardForPayment(Payment $payment): void
    {
        if (! $this->enabled()) {
            return;
        }

        $conversion = PartnerConversion::where('payment_id', $payment->id)
            ->where('status', PartnerConversion::STATUS_ACCRUED)
            ->first();

        if (! $conversion) {
            return;
        }

        try {
            $conversion->delete();
        } catch (\Throwable $e) {
            Log::warning('PartnerService::reverseRewardForPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Квалифицирует ли платёж на вознаграждение: реальная (не conditional), с
     * положительной суммой, по курсу, и НЕ deposit/trial/Расход/salary_payout.
     * Зеркалит ReferralService — единый смысл «первой реальной оплаты курса».
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

    /** Уже награждён за этого клиента? */
    private function alreadyRewarded(User $client): bool
    {
        return PartnerConversion::where('user_id', $client->id)->exists();
    }
}
