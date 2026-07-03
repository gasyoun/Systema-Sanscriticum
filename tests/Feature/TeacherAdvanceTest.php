<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherPayoutPoster;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeacherAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private TeacherSalaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->service = app(TeacherSalaryService::class);
    }

    /**
     * Препод с фикс-ЗП 12000 за курс + одна оплата студента (чтобы начисление
     * признали). earned_all_time = 12000.
     */
    private function teacherEarning12000(): Teacher
    {
        $teacher = Teacher::create(['name' => 'Препод Аванс']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'fix_total',
            'salary_value' => 12000,
        ]);

        Payment::withoutEvents(fn () => Payment::create([
            'course_id' => $course->id,
            'user_id' => User::factory()->create()->id,
            'amount' => 20000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        return $teacher->fresh('courses');
    }

    /** @test */
    public function unsettled_advance_does_not_reduce_balance(): void
    {
        $teacher = $this->teacherEarning12000();

        $teacher->payouts()->create([
            'amount' => 5000,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->toDateString(),
        ]);

        $row = $this->service->summaryForAll(now()->format('Y-m'))[$teacher->id];

        // Аванс выдан, но не зачтён: «К выплате» = полное начисление, аванс отдельно.
        $this->assertSame(12000.0, $row['balance']);
        $this->assertSame(0.0, $row['paid_all_time']);
        $this->assertSame(5000.0, $row['advances_outstanding']);
    }

    /** @test */
    public function settled_advance_counts_as_paid_and_reduces_balance(): void
    {
        $teacher = $this->teacherEarning12000();

        $advance = $teacher->payouts()->create([
            'amount' => 5000,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->toDateString(),
        ]);

        // Зачёт аванса — как экшен «Зачесть аванс»: settled_at + settled_amount.
        $advance->update(['settled_at' => now(), 'settled_amount' => $advance->amount]);

        $row = $this->service->summaryForAll(now()->format('Y-m'))[$teacher->id];

        $this->assertSame(5000.0, $row['paid_all_time']);
        $this->assertSame(7000.0, $row['balance']);
        $this->assertSame(0.0, $row['advances_outstanding']);
    }

    /** @test */
    public function full_payment_with_settlement_zeroes_balance(): void
    {
        $teacher = $this->teacherEarning12000();

        $advance = $teacher->payouts()->create([
            'amount' => 5000,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->toDateString(),
        ]);

        // Полная выплата: доплата 7000 (остаток − аванс) + зачёт аванса — как делает
        // recordPayoutAction с тумблером «Зачесть аванс».
        $teacher->payouts()->create([
            'amount' => 7000,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => now()->toDateString(),
            'period_month' => now()->format('Y-m'),
        ]);
        $advance->update(['settled_at' => now(), 'settled_amount' => $advance->amount]);

        $row = $this->service->summaryForAll(now()->format('Y-m'))[$teacher->id];

        $this->assertSame(12000.0, $row['paid_all_time']);
        $this->assertSame(0.0, $row['balance']);
        $this->assertSame(0.0, $row['advances_outstanding']);
    }

    /** @test */
    public function advance_posts_negative_salary_payout_mirror(): void
    {
        $teacher = $this->teacherEarning12000();

        $advance = $teacher->payouts()->create([
            'amount' => 5000,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->toDateString(),
        ]);

        $payment = app(TeacherPayoutPoster::class)->post($advance);

        $this->assertNotNull($payment);
        $this->assertSame('salary_payout', $payment->tariff);
        $this->assertEqualsWithDelta(-5000.0, (float) $payment->amount, 0.01);

        // Зачёт денег не двигает (отдельная транзакция не создаётся).
        $this->assertSame(1, Payment::where('tariff', 'salary_payout')->count());
    }
}
