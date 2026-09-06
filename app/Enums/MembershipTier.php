<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Club = 'club';
    // H3916: годовая подписка на архив «в записи» (модель VedaTNG).
    // Standard = архив записей; Professional = Standard + живые вебинары года
    // + бонусный курс. Ранги сознательно совпадают с Club/Top: подписка
    // открывает ТЕ ЖЕ полки, что клуб, но продаётся годом, а не месяцем.
    case Standard = 'standard';
    case Professional = 'professional';
    case Top = 'top';

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Basic => 10,
            self::Club, self::Standard => 20,
            self::Professional, self::Top => 30,
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

    /**
     * Цена за срок. H3916: у подписочных тиров точные цены за срок
     * (`membership.tiers.{code}.term_prices.{months}`) приоритетны — сетка
     * ратифицирована MG 06-09-2026 как 20 000/35 000 ₽ за год и 5 500 ₽ за
     * квартал, месячная математика с округлением её не воспроизводит.
     */
    public function priceForTerm(int $months): int
    {
        $exact = config("membership.tiers.{$this->value}.term_prices.{$months}");

        if ($exact !== null) {
            return (int) $exact;
        }

        $discount = (int) config("membership.terms.{$months}.discount_percent", 0);

        return (int) round($this->monthlyPrice() * $months * (100 - $discount) / 100);
    }
}
