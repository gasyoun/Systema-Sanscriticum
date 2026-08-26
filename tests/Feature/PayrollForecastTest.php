<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PayoutForecastService;
use App\Services\Payroll\PayrollRateCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3532 — формулы «на руки» (×92 %)×ставка(t) против якорей реестра H3531
 * и структура годовой сетки. Фенс: money-таблицы read-only.
 */
class PayrollForecastTest extends TestCase
{
    use RefreshDatabase;

    private function calc(): PayrollRateCalculator
    {
        return new PayrollRateCalculator((array) require config_path('teacher_rates.php'));
    }

    /** @test */
    public function kostina_anchor_a2_reproduces_marina_math(): void
    {
        $res = $this->calc()->netFor('kostina', '2026-05-11', [
            'receipts_rub' => [30000.0, 36000.0, 24000.0],
            'fx_rate' => 86.85,
        ]);

        $this->assertSame(24840.0, $res['payable_rub']);
        $this->assertEqualsWithDelta(286.0, $res['payable_eur'], 0.05);
        $this->assertSame(6.0, $res['npd_pct']);
        $this->assertSame(23349.6, $res['net_after_npd_rub']);
        $this->assertTrue(collect($res['notes'])->contains(fn ($n) => str_contains($n, 'НПД')));
        // НПД — пометка шага выплаты, НЕ внутри брутто (ARCHITECT контракт №3)
        $this->assertSame(24840.0, $res['payable_rub']);
    }

    /** @test */
    public function leytan_anchor_a1_is_exact_including_deduction_and_recalc(): void
    {
        $res = $this->calc()->netFor('leytan', '2025-07-20', [
            'receipts_rub' => [81100.0],
            'recalc_rub' => 3120.0,
        ]);

        $this->assertSame(54155.0, $res['payable_rub']); // 81 100 × 65% − 1 680 + 3 120
        $this->assertSame(1680.0, $res['deductions_rub']);
    }

    /** @test */
    public function leytan_deduction_timeline_evolution(): void
    {
        $calc = $this->calc();

        $this->assertSame(1400.0, $calc->netFor('leytan', '2024-12-23', ['receipts_rub' => []])['deductions_rub']);
        $this->assertSame(1680.0, $calc->netFor('leytan', '2025-08-01', ['receipts_rub' => []])['deductions_rub']);
        $this->assertSame(1920.0, $calc->netFor('leytan', '2026-04-19', ['receipts_rub' => []])['deductions_rub']);
    }

    /** @test */
    public function bank_slice_applies_only_when_period_carries_it(): void
    {
        $calc = $this->calc();

        // Ворошилов до фикса: эра без среза → 100 000 × 30 % = 30 000 (без ×92 %)
        $noSlice = $calc->netFor('voroshilov', '2025-12-01', ['receipts_rub' => [100000.0]]);
        $this->assertSame(30000.0, $noSlice['payable_rub']);

        // Костина в эру среза: 10 000 ×92 %×30 % = 2 760
        $sliced = $calc->netFor('kostina', '2026-05-11', ['receipts_rub' => [10000.0]]);
        $this->assertSame(2760.0, $sliced['payable_rub']);
    }

    /** @test */
    public function voroshilov_fix_period_pays_flat_25000_per_block(): void
    {
        $res = $this->calc()->netFor('voroshilov', '2026-03-23');

        $this->assertSame(25000.0, $res['payable_rub']); // якорь A3
    }

    /** @test */
    public function direct_student_payments_multiply_without_bank_slice(): void
    {
        // Дружинин фев: 36 000×92%×75% + прямой 4 000×75% = 27 840 (msg 3284)
        $res = $this->calc()->netFor('druzhinin', '2026-02-26', [
            'receipts_rub' => [36000.0],
            'direct_receipts_rub' => [4000.0],
        ]);

        $this->assertSame(27840.0, $res['payable_rub']);
    }

    /** @test */
    public function staff_kravchenko_base_plus_premium_rows(): void
    {
        // A4 база 17 030 + премия строкой 10 650 (запись Марии 14.02)
        $res = $this->calc()->netFor('kravchenko', '2026-02-09', ['premium_rub' => [10650.0]]);

        $this->assertSame(27680.0, $res['payable_rub']);
    }

    /** @test */
    public function contractor_kholovchenko_returns_fee_range(): void
    {
        $res = $this->calc()->netFor('kholovchenko', '2026-05-15');

        $this->assertSame('contractor', $res['kind']);
        $this->assertSame(['min' => 200.0, 'max' => 300.0], $res['eur_range']);
    }

    /** @test */
    public function rate_gap_falls_back_with_warning_note(): void
    {
        // Периоды Лейтана заканчиваются 2026-07-23 — сентябрь вне таймлайна.
        $res = $this->calc()->netFor('leytan', '2026-09-29', ['receipts_rub' => [5000.0]]);

        $this->assertSame('lms_fallback', $res['kind']);
        $this->assertTrue(collect($res['notes'])->contains(fn ($n) => str_contains($n, '⚠️')));
    }

    /** @test */
    public function alias_polykarpova_matches_ilyushina(): void
    {
        $this->assertSame('ilyushina', $this->calc()->matchName('Мария Поликарпова'));
        $this->assertSame('kostina', $this->calc()->matchName('Екатерина Костина'));
    }

    /** @test */
    public function year_grid_builds_weeks_staff_and_contractor_read_only(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Максим Ворошилов']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        CourseBlock::factory()->create([
            'course_id' => $course->id,
            'number' => 4,
            'ends_at' => '2026-09-29 18:00:00',
        ]);

        $svc = app(PayoutForecastService::class);
        $before = $svc->fingerprint();
        $grid = $svc->yearGrid(2026);

        $this->assertGreaterThanOrEqual(52, count($grid['weeks']));
        $this->assertFalse($grid['money_tables_moved']);

        $week = collect($grid['weeks'])->firstWhere('iso_week', Carbon::parse('2026-09-29')->isoWeek);
        $this->assertNotNull($week);
        $due = collect($week['due'])->first(fn ($d) => $d['name'] === 'Максим Ворошилов');
        $this->assertNotNull($due);
        $this->assertSame(25000.0, $due['amount_rub_prelim']);
        $this->assertTrue($due['preliminary']);
        $this->assertSame('tochka_maria', $due['channel']);

        // Штат присутствует одной сеткой с преподавателями (рулинг #10)
        $allNames = collect($grid['weeks'])->flatMap(fn ($w) => collect($w['due'])->pluck('name'))->unique();
        $this->assertTrue($allNames->contains(fn ($n) => str_contains($n, 'Горбаченко')));
        $this->assertTrue($allNames->contains(fn ($n) => str_contains($n, 'Кравченко')));
        $this->assertTrue($allNames->contains(fn ($n) => str_contains($n, 'Холовченко')));

        // EUR-получатель (Костина) даёт €-потребность и пометку НПД где применимо
        $eurDue = collect($grid['weeks'])
            ->flatMap(fn ($w) => $w['due'])
            ->first(fn ($d) => ($d['lane'] ?? '') === 'EUR');
        $this->assertNotNull($eurDue, 'EUR-lane recipient must appear in the grid');
        $this->assertNotNull($eurDue['amount_eur_prelim']);

        $this->assertSame($before, $svc->fingerprint());
    }

    /** @test */
    public function paypal_balance_recording_touches_only_finance_snapshots(): void
    {
        $svc = app(PayoutForecastService::class);
        $user = User::factory()->create();

        $before = $svc->fingerprint();
        $snap = $svc->recordPaypalBalance(1250.555, $user->id);
        $after = $svc->fingerprint();

        $this->assertSame($before, $after); // teacher_payouts / payments / users не тронуты
        $this->assertSame(FinanceSnapshot::TYPE_PAYPAL_BALANCE, $snap->type);
        $this->assertSame(125056, $snap->amount_minor);
        $this->assertSame(1250.56, $snap->majorAmount());
        $this->assertNotNull($snap->entered_at);
        $this->assertSame($user->id, $snap->user_id);

        $latest = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE);
        $this->assertSame($snap->id, $latest?->id);

        $balance = $svc->paypalBalance();
        $this->assertSame(1250.56, $balance['balance_eur']);
        $this->assertNotNull($balance['entered_at']);
    }

    /** @test */
    public function fx_fallback_until_manual_snapshot_exists(): void
    {
        $svc = app(PayoutForecastService::class);
        $fallback = $svc->fxEur();
        $this->assertSame('config_fallback (исторический)', $fallback['source']);

        FinanceSnapshot::query()->create([
            'type' => FinanceSnapshot::TYPE_FX_EUR_RUB,
            'amount_minor' => 8685, // 86.85 ₽ за €, минорные копейки
            'currency' => 'RUB',
            'entered_at' => now(),
        ]);
        $manual = $svc->fxEur();
        $this->assertSame(86.85, $manual['rate']);
        $this->assertSame('finance_snapshots', explode(' @ ', $manual['source'])[0]);
    }
}
