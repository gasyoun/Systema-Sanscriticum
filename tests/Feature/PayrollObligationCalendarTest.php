<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H6706 — H5554 gap [4], MG ruling 01-10-2026: датированный календарь
 * обязательств и пос cycle-схедул 70/30 (burn старейшие-first / дивиденды).
 * Пулы ограничены правлеными суммами; мощность цикла = доля × чистый ДДС.
 */
class PayrollObligationCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payroll_readiness.evidence_manifest_path', storage_path('framework/testing/missing-payroll-evidence.json'));
        config()->set('services.tochka.token', '');
        config()->set('payroll_readiness.owner_remainder_cut', [
            'amount' => 1000.0,
            'as_of' => '2026-09-29',
            'burn_share' => 0.7,
            'dividend_share' => 0.3,
            'burn_capacity_basis' => 'dds_net',
        ]);
        Cache::forget('tochka.open_banking.balances');
    }

    /** @test */
    public function json_enumerates_dated_obligations_and_burns_oldest_first(): void
    {
        [$older, $later] = $this->seedTwoPayableTeachers();
        TeacherPayout::query()->create([
            'teacher_id' => $older->id,
            'amount' => 250.0,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => '2026-08-10',
        ]);
        $this->seedCashPosition(inflow: 10000.0, opex: 3000.0, deposit: 400.0);

        Artisan::call('payroll:obligation-calendar', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('payroll-obligation-calendar/v1', $payload['schema']);

        // Пулы правления: 1000 × 70/30.
        $this->assertSame(700.0, (float) $payload['ruling']['burn_pool']);
        $this->assertSame(300.0, (float) $payload['ruling']['dividend_pool']);
        $this->assertSame('2026-09-29', $payload['ruling']['cut_as_of']);

        // Перечень: оба преподавателя с суммами и датами окна/дюлей, аванс —
        // дебиторка, депозит — пассив.
        $teachers = array_values(array_filter($payload['obligations'], fn (array $o): bool => $o['kind'] === 'teacher_obligation'));
        $this->assertCount(2, $teachers);
        $byRef = collect($teachers)->keyBy('ref');
        // Readiness-арифметика: 10 000 × 92 % × 30 % = 2 760, минус непогашенный
        // аванс 250 (см. ниже) — тот же remaining_obligation, что в кабинете.
        $this->assertSame(2510.0, (float) $byRef['teacher_'.$older->id]['amount_rub']);
        // since = последняя фактическая выплата (1 ₽ 31-07, аванс 10-08).
        $this->assertSame('2026-08-10', $byRef['teacher_'.$older->id]['since']);
        $this->assertNotSame('', $byRef['teacher_'.$older->id]['due_on']);

        $kinds = array_column($payload['obligations'], 'kind');
        $this->assertContains('receivable_advance', $kinds);
        $this->assertContains('deposit_liability', $kinds);
        $advance = $payload['obligations'][array_search('receivable_advance', $kinds, true)];
        $this->assertSame(250.0, (float) $advance['amount_rub']);
        $this->assertSame('2026-08-10', $advance['since']);

        // Схедул: мощность цикла = 70% × чистый ДДС (10000 − 3000 = 7000).
        $cycle = $payload['burn_schedule'][0];
        $this->assertSame(4900.0, (float) $cycle['burn_capacity']);
        $this->assertSame(2100.0, (float) $cycle['dividend_capacity']);

        // Burn ограничен пулом 700 и идёт старейшие-first по дате: депозит
        // (создан 20-08) старше обоих teacher-обязательств (дюль 01/02-09),
        // поэтому гасится первым и целиком; остаток пула — частичное гашение
        // старейшего teacher-обязательства. Дивиденды — 300 (пул).
        $this->assertCount(2, $cycle['allocations']);
        $this->assertSame('deposit_liability', $cycle['allocations'][0]['kind']);
        $this->assertSame(400.0, (float) $cycle['allocations'][0]['amount']);
        $this->assertTrue($cycle['allocations'][0]['obligation_closed']);
        $this->assertSame('teacher_obligation', $cycle['allocations'][1]['kind']);
        $this->assertSame('teacher_'.$older->id, $cycle['allocations'][1]['ref']);
        $this->assertSame(300.0, (float) $cycle['allocations'][1]['amount']);
        $this->assertFalse($cycle['allocations'][1]['obligation_closed']);
        $this->assertSame(300.0, (float) $cycle['dividends']);
        $this->assertSame(0.0, (float) $payload['pools_after_schedule']['burn']);
        $this->assertSame(0.0, (float) $payload['pools_after_schedule']['dividends']);
    }

    /** @test */
    public function negative_cycle_net_stops_the_burn_for_that_cycle(): void
    {
        $this->seedTwoPayableTeachers();
        $this->seedCashPosition(inflow: 0.0, opex: 3000.0, deposit: 0.0);

        Artisan::call('payroll:obligation-calendar', [
            '--cycles' => '2',
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // Пол мощности: отрицательный ДДС ничего не прожигает и не платит.
        foreach ($payload['burn_schedule'] as $cycle) {
            $this->assertSame(0.0, (float) $cycle['burn_capacity']);
            $this->assertSame([], $cycle['allocations']);
            $this->assertSame(0.0, (float) $cycle['dividends']);
        }
        // Пулы нетронуты.
        $this->assertSame(700.0, (float) $payload['pools_after_schedule']['burn']);
        $this->assertSame(300.0, (float) $payload['pools_after_schedule']['dividends']);
    }

    /** @test */
    public function bad_format_fails_closed(): void
    {
        $this->artisan('payroll:obligation-calendar', ['--format' => 'yaml'])->assertExitCode(1);
    }

    /**
     * Два payable-преподавателя с разными датами завершения блока (арефметика
     * readiness: 10 000 × 92 % × 30 % = 2 760 каждому); имена — слоты
     * config/teacher_rates.php. Остальные 21 — неактивный фон ценза.
     *
     * @return array{0: Teacher, 1: Teacher}
     */
    private function seedTwoPayableTeachers(): array
    {
        $older = Teacher::factory()->create(['name' => 'Толчельников Иван']);
        $later = Teacher::factory()->create(['name' => 'Трефилова Елена']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($older, '2026-09-01');
        $this->seedPayable($later, '2026-09-02');

        return [$older, $later];
    }

    private function seedPayable(Teacher $teacher, string $completedOn): void
    {
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::query()->create([
            'course_id' => $course->id,
            'number' => 1,
            'is_active' => true,
            'starts_at' => '2026-08-01',
            'ends_at' => $completedOn,
        ]);
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 10000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'created_at' => '2026-08-15 10:00:00',
        ]));
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 1,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-07-31',
        ]);
    }

    /**
     * Кассовая позиция текущего цикла: приток (paid, касса школы), opex и
     * непотраченный депозит (тоже приток по ДДС).
     */
    private function seedCashPosition(float $inflow, float $opex, float $deposit): void
    {
        if ($inflow > 0) {
            $student = User::factory()->create();
            $course = Course::factory()->create();
            Payment::withoutEvents(fn () => Payment::query()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'paid',
                'tariff' => 'full',
                'amount' => $inflow,
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
                'created_at' => now(),
            ]));
        }
        if ($deposit > 0) {
            $student = User::factory()->create();
            $course = Course::factory()->create();
            Payment::withoutEvents(fn () => Payment::query()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'paid',
                'tariff' => 'deposit',
                'amount' => $deposit,
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
                'created_at' => '2026-08-20 10:00:00',
            ]));
        }
        if ($opex > 0) {
            Expense::create([
                'spent_at' => now(),
                'category' => ExpenseCategory::Hosting,
                'amount' => $opex,
            ]);
        }
    }
}
