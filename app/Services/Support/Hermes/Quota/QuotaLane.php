<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: полосы расхода подписочной квоты школьной роли — принятая схема
 * 60/20/20 (штатная работа / проверки / резерв) из стратегии 04-10-2026.
 *
 * Доли живут в config/school_role_quota.php (lane_share_pct), не здесь.
 */
enum QuotaLane: string
{
    case ROUTINE = 'routine';

    case CHECK = 'check';

    case RESERVE = 'reserve';

    /** Доля полосы в процентах от квоты роли (целочисленно, без float). */
    public function sharePct(): int
    {
        $pct = config('school_role_quota.lane_share_pct.'.$this->value);

        if (! is_int($pct) && ! ctype_digit((string) $pct)) {
            throw new \RuntimeException(
                'school_role_quota.lane_share_pct.'.$this->value.' is not configured (config defect)'
            );
        }

        return (int) $pct;
    }

    /** Потолок полосы в токенах: intdiv(allowance × sharePct, 100) — floor, консервативно. */
    public function cap(int $allowance): int
    {
        return intdiv($allowance * $this->sharePct(), 100);
    }

    /** Имя колонки учёта закоммиченных токенов полосы в school_role_quotas. */
    public function committedColumn(): string
    {
        return $this->value.'_committed';
    }

    /** Имя колонки учёта зарезервированных токенов полосы в school_role_quotas. */
    public function reservedColumn(): string
    {
        return $this->value.'_reserved';
    }
}
