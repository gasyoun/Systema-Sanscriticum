<?php

declare(strict_types=1);

namespace App\Enums;

/** How subscription charge amount is derived (H2026 §4.2). */
enum BillingAmountPolicy: string
{
    case Fixed = 'fixed';
    case SumCommitments = 'sum_commitments';
    case InstallmentDue = 'installment_due';
}
