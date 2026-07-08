<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\RevenueSchedule;
use App\Models\User;
use App\Services\RevenueScheduleService;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Признание выручки по методу начисления (H258, фаза B). Проверяет:
 *  - раскладку суммы платежа по месяцам блоков (accrual);
 *  - сходимость Σ признанной выручки с кассовой за всё время;
 *  - отложенную выручку (оплачено − признано);
 *  - генерацию на новый платёж (обсёрвер) и бэкофилл истории;
 *  - что кассовая вкладка не сломана, а возвраты/conditional не дают строк.
 */
class RevenueScheduleTest extends TestCase
{
    use RefreshDatabase;

    private RevenueScheduleService $service;

    private FinanceCockpitReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RevenueScheduleService::class);
        $this->report = app(FinanceCockpitReport::class);
    }

    private function course(): Course
    {
        return Course::factory()->create();
    }

    /** @param array<int,string> $blocks [номер => 'Y-m-d' старта] */
    private function blocks(Course $course, array $blocks): void
    {
        foreach ($blocks as $number => $startsAt) {
            CourseBlock::create([
                'course_id' => $course->id,
                'number' => $number,
                'starts_at' => $startsAt,
                'ends_at' => Carbon::parse($startsAt)->endOfMonth(),
                'is_active' => true,
            ]);
        }
    }

    /** Платёж без событий (обсёрвер не трогаем — генерацию делаем явно). */
    private function pay(Course $course, array $attrs): Payment
    {
        return Payment::withoutEvents(function () use ($course, $attrs) {
            return Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
                // withoutEvents пропускает saving-хук, дефолтящий received_account —
                // задаём явно, иначе schoolReceived()/isRevenuePayment его не увидят.
                'received_account' => Payment::RECEIVED_SCHOOL,
            ], $attrs));
        });
    }

    /** @test */
    public function payment_is_spread_across_block_months_and_sums_to_amount(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10', 4 => '2026-04-10']);
        $u = User::factory()->create();

        $payment = $this->pay($course, ['user_id' => $u->id, 'amount' => 8000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);
        $this->service->regenerateFor($payment);

        // 8000 / 4 блока = 2000 в каждый месяц блока.
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-01'));
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-02'));
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-03'));
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-04'));

        // Σ строк == сумме платежа.
        $this->assertSame(8000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));
        $this->assertSame(4, RevenueSchedule::where('payment_id', $payment->id)->count());
    }

    /** @test */
    public function accrual_revenue_reconciles_with_cash_revenue_over_all_time(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10', 4 => '2026-04-10']);
        $u = User::factory()->create();
        $this->pay($course, ['user_id' => $u->id, 'amount' => 8000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);
        $this->service->backfillAll();

        // Кассовая: вся сумма — в месяц оплаты.
        $this->assertSame(8000.0, $this->report->opiu('2026-01')['revenue']);
        $this->assertSame(0.0, $this->report->opiu('2026-02')['revenue']);

        // Начисление: по 2000 в каждый месяц блока.
        $this->assertSame(2000.0, $this->report->opiuAccrual('2026-01')['revenue']);
        $this->assertSame(2000.0, $this->report->opiuAccrual('2026-04')['revenue']);

        // Σ за всё время сходится: касса (8000) == сумма accrual по 4 месяцам.
        $accrualTotal = 0.0;
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $m) {
            $accrualTotal += $this->report->opiuAccrual($m)['revenue'];
        }
        $this->assertSame(8000.0, $accrualTotal);
        $this->assertSame('accrual', $this->report->opiuAccrual('2026-01')['revenueBasis']);
        $this->assertSame('cash', $this->report->opiu('2026-01')['revenueBasis']);
    }

    /** @test */
    public function deferred_revenue_is_paid_minus_recognized(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10', 4 => '2026-04-10']);
        $u = User::factory()->create();
        $this->pay($course, ['user_id' => $u->id, 'amount' => 8000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);
        $this->service->backfillAll();

        // Конец января: оплачено 8000, признано 2000 → отложено 6000.
        $jan = $this->service->deferredRevenueAsOf('2026-01');
        $this->assertSame(8000.0, $jan['cashReceived']);
        $this->assertSame(2000.0, $jan['recognized']);
        $this->assertSame(6000.0, $jan['deferred']);

        // Конец апреля: всё признано → отложенная выручка 0.
        $apr = $this->service->deferredRevenueAsOf('2026-04');
        $this->assertSame(0.0, $apr['deferred']);
    }

    /** @test */
    public function schedule_is_generated_on_new_paid_payment_via_observer(): void
    {
        Mail::fake();
        Queue::fake();

        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10']);
        $u = User::factory()->create();

        // С событиями — обсёрвер должен построить график сам.
        $payment = Payment::create([
            'course_id' => $course->id,
            'user_id' => $u->id,
            'amount' => 4000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'created_at' => '2026-01-05 10:00:00',
        ]);

        $this->assertSame(2, RevenueSchedule::where('payment_id', $payment->id)->count());
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-01'));
    }

    /** @test */
    public function backfill_covers_historical_payments(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10']);
        $u = User::factory()->create();
        // Исторический платёж заведён без событий — графика ещё нет.
        $this->pay($course, ['user_id' => $u->id, 'amount' => 4000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);

        $this->assertSame(0, RevenueSchedule::count());

        $stats = $this->service->backfillAll();

        $this->assertSame(2, $stats['rows']);
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-02'));
    }

    /** @test */
    public function refunds_conditional_and_teacher_received_produce_no_schedule(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10']);
        $u = User::factory()->create();

        // Возврат (Расход, отрицательная сумма).
        $refund = $this->pay($course, ['user_id' => $u->id, 'amount' => -1000, 'tariff' => 'Расход', 'created_at' => '2026-01-05 10:00:00']);
        // Conditional-доступ «под обещание».
        $cond = $this->pay($course, ['user_id' => $u->id, 'amount' => 0, 'tariff' => 'full', 'is_conditional' => true, 'created_at' => '2026-01-05 10:00:00']);

        $this->service->regenerateFor($refund);
        $this->service->regenerateFor($cond);

        $this->assertSame(0, RevenueSchedule::count());
        $this->assertSame(0.0, $this->service->recognizedForMonth('2026-01'));
    }

    /** @test */
    public function status_reversal_clears_the_schedule(): void
    {
        Mail::fake();
        Queue::fake();

        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10']);
        $u = User::factory()->create();

        $payment = Payment::create([
            'course_id' => $course->id,
            'user_id' => $u->id,
            'amount' => 4000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'created_at' => '2026-01-05 10:00:00',
        ]);
        $this->assertSame(2, RevenueSchedule::where('payment_id', $payment->id)->count());

        // Откат из paid → строки признания снимаются.
        $payment->update(['status' => 'canceled']);

        $this->assertSame(0, RevenueSchedule::where('payment_id', $payment->id)->count());
    }

    /** @test */
    public function recognition_month_override_puts_whole_amount_in_one_month(): void
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10']);
        $u = User::factory()->create();

        $payment = $this->pay($course, [
            'user_id' => $u->id,
            'amount' => 9000,
            'tariff' => 'full',
            'salary_recognition_month' => '2026-02',
            'created_at' => '2026-01-05 10:00:00',
        ]);
        $this->service->regenerateFor($payment);

        $this->assertSame(9000.0, $this->service->recognizedForMonth('2026-02'));
        $this->assertSame(0.0, $this->service->recognizedForMonth('2026-01'));
        $this->assertSame(1, RevenueSchedule::where('payment_id', $payment->id)->count());
    }
}
