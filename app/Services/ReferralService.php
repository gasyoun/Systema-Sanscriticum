<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PranaTransaction;
use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Support\Facades\Log;

/**
 * Реферальная программа: приглашённый студент привязывается к пригласившему по
 * коду, и когда приглашённый ВПЕРВЫЕ оплачивает курс — пригласившему начисляется
 * прана (reason 'referral'). Награда за приглашённого выдаётся ровно один раз.
 */
class ReferralService
{
    public const REWARD_REASON = 'referral';

    public function __construct(private PranaService $prana) {}

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
     * Начислить пригласившему прану за первую оплату приглашённого. Вызывается из
     * PaymentObserver при переходе платежа в paid. Награда — один раз на каждого
     * приглашённого студента (гейт по наличию транзакции с source = студент).
     */
    public function rewardForPayment(Payment $payment): void
    {
        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        $referred = $payment->user;
        if (! $referred || blank($referred->referred_by)) {
            return;
        }

        $referrer = $referred->referrer;
        if (! $referrer) {
            return;
        }

        // Уже награждали за этого приглашённого? (source = приглашённый студент)
        $already = PranaTransaction::query()
            ->where('reason', self::REWARD_REASON)
            ->where('source_type', $referred->getMorphClass())
            ->where('source_id', $referred->getKey())
            ->exists();

        if ($already) {
            return;
        }

        try {
            $this->prana->award($referrer, self::REWARD_REASON, $referred, meta: [
                'referred_user_id' => $referred->id,
                'payment_id' => $payment->id,
            ]);
        } catch (\Throwable $e) {
            // Награда не должна ломать оплату.
            Log::warning('ReferralService::rewardForPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
