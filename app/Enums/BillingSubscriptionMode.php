<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tochka recurring product mode (H2026).
 * Values match architecture §4.2 / config allow-list tokens.
 */
enum BillingSubscriptionMode: string
{
    case PerCourse = 'per_course';
    case Consolidated = 'consolidated';
    case Club = 'club';
    case Installment = 'installment';
    case MultiMonth = 'multi_month';

    public function label(): string
    {
        return match ($this) {
            self::PerCourse => 'По курсу (день оплаты)',
            self::Consolidated => 'Единый платёж по направлениям',
            self::Club => 'Клуб',
            self::Installment => 'Оплата по частям (автосбор)',
            self::MultiMonth => 'Несколько месяцев сразу',
        };
    }
}
