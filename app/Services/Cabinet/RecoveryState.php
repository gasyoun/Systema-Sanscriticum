<?php

declare(strict_types=1);

namespace App\Services\Cabinet;

/**
 * Результат RecoveryStateResolver (R29.2 / H1481).
 *
 * active=true → главная в recovery-режиме: баннер проблемы ведёт,
 * ВСЕ офферы безусловно подавлены, owned/live доступ продолжает работать.
 *
 * Offer precedence (спека §1): recovery → zero offers; normal → at most one
 * primary offer. Это значение — единственный server-side источник truth
 * для suppressOffers / mode телеметрии cabinet.home.view.
 */
final class RecoveryState
{
    public const REASON_NONE = 'none';

    public const REASON_DECLINED_PAYMENT = 'declined_payment';

    public const REASON_EXPIRED_PROMISE = 'expired_promise';

    /**
     * @param  list<int>  $faultPaymentIds
     * @param  list<int>  $faultPromiseIds
     */
    public function __construct(
        public readonly bool $active,
        public readonly string $reason,
        public readonly ?string $headline = null,
        public readonly ?string $detail = null,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $ctaLabel = null,
        public readonly array $faultPaymentIds = [],
        public readonly array $faultPromiseIds = [],
        public readonly ?int $courseId = null,
        public readonly ?string $courseTitle = null,
    ) {}

    public static function normal(): self
    {
        return new self(active: false, reason: self::REASON_NONE);
    }

    /** R29.2 / offer-precedence: recovery → zero offers. */
    public function suppressOffers(): bool
    {
        return $this->active;
    }

    /** Значение mode для cabinet.home.view (H962 §4). */
    public function mode(): string
    {
        return $this->active ? 'recovery' : 'normal';
    }

    /** Owned/live access keeps working — always true by design (R29.2). */
    public function ownedAccessKeepsWorking(): bool
    {
        return true;
    }
}
