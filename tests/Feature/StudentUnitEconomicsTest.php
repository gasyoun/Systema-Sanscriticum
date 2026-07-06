<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\StudentUnitEconomics;
use App\Models\AdPostSpend;
use App\Models\Course;
use App\Models\DirectAdSpend;
use App\Models\Payment;
use App\Models\User;
use App\Services\StudentUnitEconomicsService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ручная сверка юнит-экономики ученика на одной известной когорте (H256):
 * маркетинг Jan 2026 = 36 000 ₽, привлечено 3 ученика в январе → CAC 12 000.
 */
class StudentUnitEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private Course $courseA;

    private Course $courseB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $this->courseA = Course::factory()->create(['title' => 'Йога-1']);
        $this->courseB = Course::factory()->create(['title' => 'Санскрит']);

        // ── Маркетинг за январь: Директ 30 000 + пост 6 000 = 36 000 ────────
        DirectAdSpend::create([
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-31',
            'specialist_salary' => 0,
            'ad_budget' => 30000,
        ]);
        AdPostSpend::create([
            'posted_on' => '2026-01-15',
            'title' => 'Пост',
            'budget' => 6000,
            'writers_count' => 10,
        ]);

        // ── Когорта января ─────────────────────────────────────────────────
        // S1: курс A, block_1 (янв) + block_2 (фев) → LTV 10 000, span 2 мес.
        $s1 = User::factory()->create();
        $this->pay($s1, $this->courseA, 'block_1', 5000, '2026-01-10');
        $this->pay($s1, $this->courseA, 'block_2', 5000, '2026-02-10');
        // S2: курс A, только block_1 (янв) → LTV 5 000, span 1 мес.
        $s2 = User::factory()->create();
        $this->pay($s2, $this->courseA, 'block_1', 5000, '2026-01-15');
        // S3: курс B, full (янв) → LTV 8 000, span 1 мес.
        $s3 = User::factory()->create();
        $this->pay($s3, $this->courseB, 'full', 8000, '2026-01-20');

        // Вне окна: первая покупка в декабре 2025 — не входит в январскую когорту.
        $out = User::factory()->create();
        $this->pay($out, $this->courseA, 'block_1', 5000, '2025-12-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(User $user, Course $course, string $tariff, float $amount, string $date): void
    {
        Payment::withoutEvents(function () use ($user, $course, $tariff, $amount, $date): void {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'tariff' => $tariff,
                'amount' => $amount,
                'status' => 'paid',
                'is_conditional' => false,
                'created_at' => Carbon::parse($date.' 12:00:00'),
            ]);
        });
    }

    private function report(): array
    {
        return app(StudentUnitEconomicsService::class)
            ->forPeriod(Carbon::parse('2026-01-01'), Carbon::parse('2026-07-01'));
    }

    /** @test */
    public function cac_and_ltv_match_hand_computed_cohort(): void
    {
        $e = $this->report();

        $this->assertTrue($e['found']);
        $this->assertSame(3, $e['cohort_size']);
        $this->assertSame(36000.0, $e['spend_total']);
        $this->assertSame(12000.0, $e['cac']);              // 36000 / 3

        // LTV: [5000, 8000, 10000] → среднее 7666.67, медиана 8000.
        $this->assertSame(7666.67, $e['ltv_mean']);
        $this->assertSame(8000.0, $e['ltv_median']);
        $this->assertSame(23000.0, $e['revenue_total']);
    }

    /** @test */
    public function ltv_cac_ratio_flags_red_when_below_one(): void
    {
        $e = $this->report();

        // 7666.67 / 12000 = 0.64 → красный (< 1).
        $this->assertSame(0.64, $e['ltv_cac_ratio']);
        $this->assertSame('danger', $e['ltv_cac_color']);
    }

    /** @test */
    public function months_to_payback_uses_monthly_arpu(): void
    {
        $e = $this->report();

        // span-месяцы: 2 + 1 + 1 = 4; ARPU = 23000 / 4 = 5750/мес.
        $this->assertSame(5750.0, $e['arpu_monthly']);
        // окупаемость = 12000 / 5750 = 2.086 → 2.1.
        $this->assertSame(2.1, $e['months_to_payback']);
    }

    /** @test */
    public function retention_curve_tracks_repeat_payment_months(): void
    {
        $e = $this->report();
        $curve = collect($e['retention_curve'])->keyBy('k');

        // Месяц 0: все 3 платили в свой первый месяц.
        $this->assertSame(100.0, $curve[0]['rate']);
        $this->assertSame(3, $curve[0]['active']);
        // Месяц +1: только S1 заплатил в феврале → 1 из 3.
        $this->assertSame(33.3, $curve[1]['rate']);
        $this->assertSame(1, $curve[1]['active']);
        $this->assertSame(3, $curve[1]['eligible']);
        // Месяц +2: никто → 0 из 3.
        $this->assertSame(0.0, $curve[2]['rate']);
        // Месяц +7: цель — август, ещё не наступил к 1 июля → null (никто не «успел»).
        $this->assertNull($curve[7]['rate']);
    }

    /** @test */
    public function churn_counts_students_silent_for_the_threshold(): void
    {
        $e = $this->report();

        // churn_months=6, конец=июль. S2/S3 последняя оплата — январь (6 мес назад) → отток.
        // S1 — февраль (5 мес) → удержан. 2 из 3 = 66.7%.
        $this->assertSame(66.7, $e['churn_rate']);
        $this->assertSame(33.3, $e['retention_rate']);
    }

    /** @test */
    public function by_course_breakdown_attributes_to_first_purchase(): void
    {
        $e = $this->report();
        $byCourse = collect($e['by_course'])->keyBy('title');

        $this->assertSame(2, $byCourse['Йога-1']['students']);   // S1 + S2
        $this->assertSame(7500.0, $byCourse['Йога-1']['ltv_mean']); // (10000+5000)/2
        $this->assertSame(1, $byCourse['Санскрит']['students']);    // S3
        $this->assertSame(8000.0, $byCourse['Санскрит']['ltv_mean']);
        // Сортировка по числу учеников — курс A первым.
        $this->assertSame('Йога-1', $e['by_course'][0]['title']);
    }

    /** @test */
    public function empty_period_reports_no_cohort_but_keeps_spend(): void
    {
        $e = app(StudentUnitEconomicsService::class)
            ->forPeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertFalse($e['found']);
        $this->assertSame(0, $e['cohort_size']);
        $this->assertNull($e['cac']);
    }

    /** @test */
    public function page_renders_for_finance_role(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/student-unit-economics')->assertSuccessful();
    }
}
