<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of a bank subscription row (H2026 §4.2). */
enum BillingSubscriptionStatus: string
{
    case PendingFirstPay = 'pending_first_pay';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
