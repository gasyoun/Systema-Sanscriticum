<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Club = 'club';
    case Top = 'top';

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Basic => 10,
            self::Club => 20,
            self::Top => 30,
        };
    }

    public function allows(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public function monthlyPrice(): int
    {
        return (int) config("membership.tiers.{$this->value}.monthly_price", 0);
    }

    public function priceForTerm(int $months): int
    {
        $discount = (int) config("membership.terms.{$months}.discount_percent", 0);

        return (int) round($this->monthlyPrice() * $months * (100 - $discount) / 100);
    }
}
