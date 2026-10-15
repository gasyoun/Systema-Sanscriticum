<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherSalaryService;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H6705 (H5554 gap [1] finish): обязательства на дату. Баланс канона точечный,
 * а пос cycle-строка тройного отчёта требует back-computation «к выплате»
 * преподавателям и непогашенных авансов на конец цикла.
 */
class PayrollObligationsAsOfTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function payable_shrinks_when_a_regular_payout_lands_in_a_later_cycle(): void
    {
        $teacher = $this->teacherWithJanuaryAccrual(900.0);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 300.0,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-02-20',
        ]);

        $report = app(FinanceCockpitReport::class);

        // Конец января: начислено 900, не выплачено ничего.
        $january = $report->obligationsAsOf(Carbon::parse('2026-01-31'));
        $this->assertSame(900.0, $january['teacherPayable']);
        $this->assertSame(0.0, $january['advances']);

        // Конец февраля: выплата 300 уменьшила обязательство.
        $february = $report->obligationsAsOf(Carbon::parse('2026-02-28'));
        $this->assertSame(600.0, $february['teacherPayable']);
    }

    /** @test */
    public function unsettled_advance_is_outstanding_without_reducing_payable(): void
    {
        $teacher = $this->teacherWithJanuaryAccrual(900.0);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 500.0,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => '2026-01-15',
        ]);

        $asOf = app(FinanceCockpitReport::class)->obligationsAsOf(Carbon::parse('2026-01-31'));

        // Аванс выдан, но не зачтён: он отдельной строкой (актив), а не вычетом
        // из «к выплате» — та же семантика, что у точечного balance().
        $this->assertSame(900.0, $asOf['teacherPayable']);
        $this->assertSame(500.0, $asOf['advances']);
    }

    /** @test */
    public function advance_settled_in_a_later_cycle_leaves_the_as_of_breakdown(): void
    {
        $teacher = $this->teacherWithJanuaryAccrual(900.0);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 500.0,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => '2026-01-15',
            'settled_amount' => 500.0,
            'settled_at' => '2026-02-10',
        ]);

        $report = app(FinanceCockpitReport::class);

        $january = $report->obligationsAsOf(Carbon::parse('2026-01-31'));
        $this->assertSame(500.0, $january['advances']);

        // К концу февраля аванс зачтён: из авансов ушёл, «к выплате» погасил.
        $february = $report->obligationsAsOf(Carbon::parse('2026-02-28'));
        $this->assertSame(0.0, $february['advances']);
        $this->assertSame(400.0, $february['teacherPayable']);
    }

    /** @test */
    public function as_of_now_matches_the_point_in_time_balance(): void
    {
        $teacher = $this->teacherWithJanuaryAccrual(900.0);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 300.0,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-02-20',
        ]);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 500.0,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => '2026-01-15',
        ]);

        $report = app(FinanceCockpitReport::class);
        $balance = $report->balance();
        $asOfNow = $report->obligationsAsOf(now());

        $this->assertEqualsWithDelta($balance['teacherPayable'], $asOfNow['teacherPayable'], 0.01);
        $this->assertEqualsWithDelta($balance['advances'], $asOfNow['advances'], 0.01);
    }

    /** @test */
    public function triple_report_json_wires_balance_as_of_and_unmapped_per_cycle(): void
    {
        $this->teacherWithJanuaryAccrual(900.0);
        TeacherPayout::query()->create([
            'teacher_id' => Teacher::query()->first()->id,
            'amount' => 300.0,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-02-20',
        ]);

        Artisan::call('payroll:triple-report', [
            '--periods' => '2026-01,2026-02',
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('payroll-triple-report/v1', $payload['schema']);
        $this->assertSame(900.0, (float) $payload['cycles'][0]['balance_as_of']['teacherPayable']);
        $this->assertSame(600.0, (float) $payload['cycles'][1]['balance_as_of']['teacherPayable']);

        // Свежая БД без импортов выписки: честный статус, а не «ноль».
        $this->assertSame('no_coverage', $payload['cycles'][0]['unmapped_receipts']['status']);
    }

    /**
     * Процентный курс (10 %) с одним блоком в январе и оплатой 9000 ₽: ровно
     * 900 ₽ начисления признаётся в январе.
     */
    private function teacherWithJanuaryAccrual(float $expectedJanuary): Teacher
    {
        $teacher = Teacher::create(['name' => 'Препод as-of']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 10,
        ]);
        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'starts_at' => '2026-01-10',
            'ends_at' => '2026-01-31',
            'is_active' => true,
        ]);

        $student = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $student): void {
            Payment::create([
                'course_id' => $course->id,
                'user_id' => $student->id,
                'status' => 'paid',
                'is_conditional' => false,
                'amount' => 9000.0,
                'tariff' => 'block_1',
                'start_block' => 1,
                'end_block' => 1,
                'created_at' => '2026-01-12 10:00:00',
            ]);
        });

        $earned = app(TeacherSalaryService::class)
            ->totalForTeacher($teacher->fresh('courses'), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        return $expectedJanuary > 0 && abs($earned - $expectedJanuary) > 0.01
            ? throw new \RuntimeException('Fixture broken: earned '.$earned.' in January, expected '.$expectedJanuary)
            : $teacher->fresh('courses');
    }
}
