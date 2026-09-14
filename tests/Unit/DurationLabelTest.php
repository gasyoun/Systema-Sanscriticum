<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DurationLabel;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DurationLabelTest extends TestCase
{
    /** @test */
    public function uses_whole_days_only_under_a_week(): void
    {
        $now = Carbon::parse('2026-08-21 12:00:00');

        $this->assertSame('0 дней', DurationLabel::since($now->copy(), $now));
        $this->assertSame('1 день', DurationLabel::since($now->copy()->subDay(), $now));
        $this->assertSame('5 дней', DurationLabel::since($now->copy()->subDays(5), $now));
        $this->assertSame('6 дней', DurationLabel::since($now->copy()->subDays(6)->subHours(6), $now));
    }

    /** @test */
    public function switches_to_weeks_then_months_without_decimals(): void
    {
        $now = Carbon::parse('2026-08-21 12:00:00');

        $this->assertSame('1 неделю', DurationLabel::since($now->copy()->subDays(7), $now));
        $this->assertSame('3 недели', DurationLabel::since($now->copy()->subDays(20), $now));
        $this->assertSame('4 месяца', DurationLabel::since($now->copy()->subDays(119)->subHours(6), $now));
        $this->assertSame('1 год', DurationLabel::since($now->copy()->subDays(400), $now));
    }

    /** @test */
    public function never_emits_a_fractional_day_string(): void
    {
        $now = Carbon::parse('2026-08-21 18:00:00');
        $from = Carbon::parse('2026-04-24 12:00:00');
        $label = DurationLabel::since($from, $now);

        $this->assertDoesNotMatchRegularExpression('/\d+[.,]\d+/', $label);
        $this->assertStringNotContainsString('дн.', $label);
    }
}
