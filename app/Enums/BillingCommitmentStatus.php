<?php

declare(strict_types=1);

namespace App\Enums;

/** Commitment charge lifecycle (H2026 §4.3). */
enum BillingCommitmentStatus: string
{
    case Scheduled = 'scheduled';
    case Charged = 'charged';
    case Failed = 'failed';
    case Waived = 'waived';
    case Cancelled = 'cancelled';
}
