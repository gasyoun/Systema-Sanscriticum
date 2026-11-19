<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\User;

/**
 * H6395 lever 2 (GTD WIC) — единственная точка, где решается «заявка
 * доверенная (сразу paid) или в ручную сверку».
 *
 * Зачем шов. До этого предикат доверия был скопирован в три контроллера
 * (`auth()->check() && auth()->user()->isEstablishedClaimStudent()`), а
 * `ClaimTrustPolicy` — класс, введённый H5083 ровно для этого решения, — не
 * вызывался ниоткуда (H6395-lever-2 находка: 0 % покрытия на мёртвом коде).
 * Флаг `features.claim_trust_policy` переключает предикат в ОДНОМ месте,
 * поэтому «включить политику» не требует правки контроллеров и не может
 * разъехаться между каналами.
 *
 * OFF (default — мерж прод-инертен):
 *   легаси User::isEstablishedClaimStudent() — возраст ≥
 *   CLAIM_TRUST_MIN_AGE_DAYS (7) сут ИЛИ проведённый платёж.
 * ON:
 *   ClaimTrustPolicy::isPreexistingVerified() — тот же PAID-платёж, но возраст
 *   берётся из конфига КАНАЛА (trust_min_account_age_hours), дефолт 168 ч =
 *   7 сут, то есть при пустом env семантика идентична OFF.
 *
 * Включение в проде — отдельный ops-шаг (env + `config:cache`), не часть
 * мержа PR; понижение порога ниже 168 ч — сознательное решение по денежному
 * контуру, а не побочный эффект включения.
 */
final class ClaimTrustGate
{
    /**
     * ПОЛНЫЕ пути конфига канала, а не голые имена: config('paypal.…') на
     * несуществующем ключе молча отдаёт дефолт, и порог из env был бы
     * проигнорирован (ловушка, зафиксированная тестом ClaimTrustPolicyTest).
     */
    public const PAYPAL = 'services.paypal';

    public const BANK_CLAIM = 'services.bank_claim';

    public const GASUNS_PAY = 'services.gasuns_pay';

    public function __construct(
        private readonly ClaimTrustPolicy $policy = new ClaimTrustPolicy,
    ) {}

    /** $user === null (гость) не доверяется никогда — самопроминченная сессия не основание. */
    public function trusts(?User $user, string $configNamespace): bool
    {
        if ($user === null) {
            return false;
        }

        if (! (bool) config('features.claim_trust_policy', false)) {
            return $user->isEstablishedClaimStudent();
        }

        return $this->policy->isPreexistingVerified($user, $configNamespace);
    }
}
