<?php

declare(strict_types=1);

namespace App\Enums;

/** Consolidated (mode B) charge cadence (H2026 §5.B). */
enum BillingConsolidatedCadence: string
{
    case Monthly1 = 'monthly_1';
    case Monthly2 = 'monthly_2';
}
