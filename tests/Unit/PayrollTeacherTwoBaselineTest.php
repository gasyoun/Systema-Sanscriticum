<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * H5554 gap [0] — MG ruling 29-09-2026: the teacher-2 legacy 2.6M claim is
 * reset; the payable window counts from 2026-08-01. Pins the config value so
 * an accidental revert (e.g. back to full-depth 2020-01-01) fails a test.
 * Prod-side number check stays with the verifier:
 * payout:run --teacher=2 --since=2026-08-01 → base 277880.11.
 */
final class PayrollTeacherTwoBaselineTest extends TestCase
{
    public function test_teacher_two_window_counts_from_august_2026(): void
    {
        $overrides = (array) config('payroll_readiness.teacher_since_overrides', []);

        $this->assertSame('2026-08-01', $overrides[2] ?? null);
        $since = Carbon::parse((string) ($overrides[2] ?? ''));
        $target = Carbon::parse((string) config('payroll_readiness.target_date'));

        // Fail-closed guard of configuredSinceOverride(): a future-dated
        // start is ignored, so the baseline must not exceed the target date.
        $this->assertTrue($since->lte($target));
    }
}
