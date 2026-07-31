<?php

declare(strict_types=1);

namespace App\Enums;

/** Student preference for course streams: A vs B (H2026 §4.1 / §6). */
enum BillingPreferredMode: string
{
    case PerCourse = 'per_course';
    case Consolidated = 'consolidated';
}
