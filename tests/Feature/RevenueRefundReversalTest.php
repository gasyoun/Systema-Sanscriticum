<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\RevenueSchedule;
use App\Models\User;
use App\Services\RevenueScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Реверс непризнанного остатка выручки при возврате (H352, H258 @DECIDE #2).
 * Модель — «отдельный Расход, заработанное остаётся» (решение MG 08-07-2026):
 * исходный платёж остаётся paid, возврат заводится Расход-платежом с
 * refund_of_payment_id → исходный; признание усекается по месяц возврата,
 * отложенная выручка вычитает возвращённое. Всё за флагом
 * revenue.reverse_unrecognized_on_refund (дефолт OFF = поведение до H352).
 */
class RevenueRefundReversalTest extends TestCase
{
    use RefreshDatabase;

    private RevenueScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RevenueScheduleService::class);
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

    private function pay(Course $course, array $attrs): Payment
    {
        return Payment::withoutEvents(function () use ($course, $attrs) {
            return Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
            ], $attrs));
        });
    }

    /** Возврат (Расход), привязанный к исходному платежу, заведён в месяц $month. */
    private function refund(Payment $original, float $amount, string $month): Payment
    {
        return Payment::withoutEvents(function () use ($original, $amount, $month) {
            return Payment::create([
                'course_id' => $original->course_id,
                'user_id' => $original->user_id,
                'amount' => $amount,
                'tariff' => 'Расход',
                'status' => 'paid',
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
                'refund_of_payment_id' => $original->id,
                'created_at' => $month.'-15 10:00:00',
            ]);
        });
    }

    private function fourBlockCourse(): array
    {
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10', 4 => '2026-04-10']);
        $u = User::factory()->create();
        $payment = $this->pay($course, ['user_id' => $u->id, 'amount' => 8000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);

        return [$course, $u, $payment];
    }

    /** @test */
    public function flag_off_linked_refund_does_not_truncate(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => false]);
        [, , $payment] = $this->fourBlockCourse();
        $this->refund($payment, -4000, '2026-02');

        $this->service->regenerateFor($payment);

        // Дефолт: возврат отдельным Расход не трогает график — все 4 месяца по 2000.
        $this->assertSame(8000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-04'));
    }

    /** @test */
    public function flag_on_mid_course_refund_truncates_at_refund_month(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => true]);
        [, , $payment] = $this->fourBlockCourse();
        $this->refund($payment, -4000, '2026-02');

        $this->service->regenerateFor($payment);

        // Оставляем январь+февраль (услуга оказана), март+апрель сторнируем.
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-01'));
        $this->assertSame(2000.0, $this->service->recognizedForMonth('2026-02'));
        $this->assertSame(0.0, $this->service->recognizedForMonth('2026-03'));
        $this->assertSame(0.0, $this->service->recognizedForMonth('2026-04'));
        $this->assertSame(4000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));
    }

    /** @test */
    public function flag_on_refund_before_any_block_leaves_no_revenue(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => true]);
        $course = $this->course();
        $this->blocks($course, [1 => '2026-03-10', 2 => '2026-04-10']);
        $u = User::factory()->create();
        $payment = $this->pay($course, ['user_id' => $u->id, 'amount' => 4000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);
        $this->refund($payment, -4000, '2026-02'); // возврат до старта блоков

        $this->service->regenerateFor($payment);

        $this->assertSame(0, RevenueSchedule::where('payment_id', $payment->id)->count());
    }

    /** @test */
    public function flag_on_refund_after_full_delivery_keeps_all(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => true]);
        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10']);
        $u = User::factory()->create();
        $payment = $this->pay($course, ['user_id' => $u->id, 'amount' => 4000, 'tariff' => 'full', 'created_at' => '2026-01-05 10:00:00']);
        $this->refund($payment, -4000, '2026-06'); // возврат после всех блоков

        $this->service->regenerateFor($payment);

        // Все месяцы блоков <= месяца возврата → график не тронут.
        $this->assertSame(4000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));
    }

    /** @test */
    public function flag_on_deferred_revenue_nets_the_refund(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => true]);
        [, , $payment] = $this->fourBlockCourse();
        $this->refund($payment, -4000, '2026-02');
        $this->service->regenerateFor($payment);

        // Конец февраля: касса 8000, возвращено 4000, признано 4000 → отложено 0.
        $feb = $this->service->deferredRevenueAsOf('2026-02');
        $this->assertSame(8000.0, $feb['cashReceived']);
        $this->assertSame(4000.0, $feb['refundsReturned']);
        $this->assertSame(4000.0, $feb['recognized']);
        $this->assertSame(0.0, $feb['deferred']);

        // Конец января (до возврата): возврат ещё не учтён, отложено 8000-2000=6000.
        $jan = $this->service->deferredRevenueAsOf('2026-01');
        $this->assertSame(0.0, $jan['refundsReturned']);
        $this->assertSame(6000.0, $jan['deferred']);
    }

    /** @test */
    public function flag_off_deferred_revenue_ignores_refunds(): void
    {
        config(['revenue.reverse_unrecognized_on_refund' => false]);
        [, , $payment] = $this->fourBlockCourse();
        $this->refund($payment, -4000, '2026-02');
        $this->service->regenerateFor($payment);

        // Инертно: возврат не вычитается, признание полное.
        $apr = $this->service->deferredRevenueAsOf('2026-04');
        $this->assertSame(0.0, $apr['refundsReturned']);
        $this->assertSame(8000.0, $apr['recognized']);
        $this->assertSame(0.0, $apr['deferred']);
    }

    /** @test */
    public function observer_truncates_original_when_linked_refund_created_and_lifts_on_delete(): void
    {
        Mail::fake();
        Queue::fake();
        config(['revenue.reverse_unrecognized_on_refund' => true]);

        $course = $this->course();
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10', 4 => '2026-04-10']);
        $u = User::factory()->create();

        // Исходный платёж с событиями — обсёрвер строит полный график (4×2000).
        $payment = Payment::create([
            'course_id' => $course->id,
            'user_id' => $u->id,
            'amount' => 8000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'created_at' => '2026-01-05 10:00:00',
        ]);
        $this->assertSame(4, RevenueSchedule::where('payment_id', $payment->id)->count());

        // Возврат с событиями → обсёрвер должен усечь график ИСХОДНОГО платежа.
        $refund = Payment::create([
            'course_id' => $course->id,
            'user_id' => $u->id,
            'amount' => -4000,
            'tariff' => 'Расход',
            'status' => 'paid',
            'is_conditional' => false,
            'refund_of_payment_id' => $payment->id,
            'created_at' => '2026-02-15 10:00:00',
        ]);
        $this->assertSame(4000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));

        // Удаление возврата снимает усечение — график исходного восстанавливается.
        $refund->delete();
        $this->assertSame(8000.0, (float) RevenueSchedule::where('payment_id', $payment->id)->sum('amount'));
    }
}
