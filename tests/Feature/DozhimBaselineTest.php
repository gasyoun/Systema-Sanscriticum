<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\OrderPaymentConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * NOBORING Wave 0 baseline: rate A (order→pay) + rate B (lead→converted).
 */
class DozhimBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
        $this->course = Course::factory()->create(['title' => 'Санскрит']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(array $attrs): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ], $attrs)));
    }

    /** @test */
    public function dozhim_baseline_reports_rate_a_and_rate_b_for_windows(): void
    {
        $u = User::factory()->create();
        $at = '2026-07-15 10:00:00'; // inside 30d and 90d

        // Rate A: 2 paid + 2 pending = 50%
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'pending', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'pending', 'created_at' => $at]);

        // Rate B: 4 leads, 1 converted = 25%
        Lead::factory()->create(['created_at' => $at, 'converted_at' => null]);
        Lead::factory()->create(['created_at' => $at, 'converted_at' => null]);
        Lead::factory()->create(['created_at' => $at, 'converted_at' => null]);
        Lead::factory()->create(['created_at' => $at, 'converted_at' => $at]);

        $report = app(OrderPaymentConversionService::class)->dozhimBaseline(windowsDays: [30, 90]);

        $this->assertCount(2, $report['windows']);
        $w30 = $report['windows'][0];
        $this->assertSame(30, $w30['days']);
        $this->assertSame(4, $w30['rate_a']['orders']);
        $this->assertSame(2, $w30['rate_a']['paid']);
        $this->assertSame(50.0, $w30['rate_a']['conversion_pct']);
        $this->assertSame(4, $w30['rate_b']['leads']);
        $this->assertSame(1, $w30['rate_b']['converted']);
        $this->assertSame(25.0, $w30['rate_b']['conversion_pct']);
        $this->assertStringContainsString('Rate A', $report['zayavka_definition']);
    }

    /** @test */
    public function artisan_dozhim_baseline_exits_zero_and_prints_rates(): void
    {
        $u = User::factory()->create();
        $at = '2026-07-20 10:00:00';
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]);
        Lead::factory()->create(['created_at' => $at, 'converted_at' => $at]);

        $this->artisan('dozhim:baseline', ['--days' => [90]])
            ->assertSuccessful()
            ->expectsOutputToContain('NOBORING dozhim baseline')
            ->expectsOutputToContain('Rate A');
    }

    /** @test */
    public function artisan_dozhim_baseline_json_flag_emits_valid_json(): void
    {
        $this->artisan('dozhim:baseline', ['--days' => [30], '--json' => true])
            ->assertSuccessful();
    }
}
