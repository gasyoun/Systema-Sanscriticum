<?php

declare(strict_types=1);

namespace App\Enums;

/** What a billing commitment buys (H2026 §4.3). */
enum BillingCommitmentKind: string
{
    case CourseMonth = 'course_month';
    case CourseBlock = 'course_block';
    case ClubPeriod = 'club_period';
    case InstallmentRow = 'installment_row';
    case MultiMonthPack = 'multi_month_pack';
}
