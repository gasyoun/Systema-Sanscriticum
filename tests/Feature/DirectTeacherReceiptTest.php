<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Прямые платежи на личный счёт преподавателя: исключение из выручки (против
 * двойного счёта) + авто-сбор зачёта по номиналу в валюте выплаты.
 * См. docs/direct-teacher-receipts.md, issue #270.
 */
class DirectTeacherReceiptTest extends TestCase
{
    use RefreshDatabase;

    private TeacherSalaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TeacherSalaryService::class);
    }

    private function percentTeacher(float $percent = 60, ?string $payoutCurrency = 'EUR'): array
    {
        $teacher = Teacher::create(['name' => 'Лектор', 'payout_currency' => $payoutCurrency]);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => $percent,
        ]);

        return [$teacher->fresh('courses'), $course];
    }

    /** Платёж напрямую в БД, без обсёрвера (guard/доступ/прана не нужны в setup). */
    private function pay(Course $course, array $attrs): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'course_id' => $course->id,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
        ], $attrs)));
    }

    /** @test */
    public function direct_receipt_is_excluded_from_course_revenue_no_double_count(): void
    {
        [$teacher, $course] = $this->percentTeacher(60);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // Обычный платёж в кассу школы — образует выручку.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 51000, 'tariff' => 'full']);
        // Прямой платёж на личный счёт преподавателя — НЕ выручка (иначе двойной счёт).
        $this->pay($course, [
            'user_id' => $u2->id,
            'amount' => 5000,
            'tariff' => 'full',
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 60,
            'foreign_currency' => 'EUR',
        ]);

        $break = $this->service->breakdownForTeacher($teacher);
        $row = $break['rows'][0];

        // В выручку вошли только 51000 (прямой платёж исключён).
        $this->assertSame(51000.0, $row['revenue']);
        // Начисление = 51000 × 60% = 30600 (5000 прямого платежа не участвуют).
        $this->assertSame(30600.0, $break['total']);
    }

    /** @test */
    public function direct_receipts_collected_with_date_payer_and_currency_match(): void
    {
        [$teacher, $course] = $this->percentTeacher(60, 'EUR');
        $u1 = User::factory()->create(['name' => 'Студент А']);
        $u2 = User::factory()->create(['name' => 'Студент Б']);

        // В валюте выплаты (EUR) — идёт в total.
        $this->pay($course, [
            'user_id' => $u1->id, 'amount' => 5000, 'tariff' => 'full',
            'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 60, 'foreign_currency' => 'EUR',
            'payer_note' => 'за студента А заплатил посредник, чек №1',
            'created_at' => '2026-05-05 10:00:00',
        ]);
        // В ЧУЖОЙ валюте (USD) — в total НЕ идёт, помечается mismatch.
        $this->pay($course, [
            'user_id' => $u2->id, 'amount' => 6000, 'tariff' => 'full',
            'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 70, 'foreign_currency' => 'USD',
        ]);

        $res = $this->service->directReceiptsForTeacher($teacher);

        $this->assertSame('EUR', $res['currency']);
        $this->assertSame(60.0, $res['total']); // только EUR-строка
        $this->assertCount(2, $res['lines']);

        $eur = collect($res['lines'])->firstWhere('currency', 'EUR');
        $this->assertFalse($eur['mismatch']);
        $this->assertSame('05.05.2026', $eur['date']);
        $this->assertSame('за студента А заплатил посредник, чек №1', $eur['payer_note']);
        $this->assertSame('Студент А', $eur['student']);

        $usd = collect($res['lines'])->firstWhere('currency', 'USD');
        $this->assertTrue($usd['mismatch']);
    }

    /** @test */
    public function direct_receipt_of_another_teacher_is_not_counted(): void
    {
        [$teacher, $course] = $this->percentTeacher(60, 'EUR');
        $other = Teacher::create(['name' => 'Другой', 'payout_currency' => 'EUR']);
        $u1 = User::factory()->create();

        $this->pay($course, [
            'user_id' => $u1->id, 'amount' => 5000, 'tariff' => 'full',
            'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $other->id,
            'foreign_amount' => 60, 'foreign_currency' => 'EUR',
        ]);

        $this->assertSame(0.0, $this->service->directReceiptsTotal($teacher));
    }

    /** @test */
    public function block_payout_total_subtracts_direct_offset_as_is(): void
    {
        // (72000 × 92%) × 60% = 39744; минус прямой зачёт 130 (в валюте выплаты — здесь как число).
        $withoutOffset = TeacherSalaryService::blockPayoutTotal(72000, 92, 60, 0);
        $withOffset = TeacherSalaryService::blockPayoutTotal(72000, 92, 60, 0, 0, 0, 0, 130);

        $this->assertSame(39744.0, $withoutOffset);
        $this->assertSame(39744.0 - 130, $withOffset);
    }

    /** @test */
    public function teacher_personal_payment_requires_a_teacher(): void
    {
        [$teacher, $course] = $this->percentTeacher();
        $u1 = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        // С событиями (guard в saving) — без received_by_teacher_id должно упасть.
        Payment::create([
            'course_id' => $course->id,
            'user_id' => $u1->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_TEACHER,
        ]);
    }

    /** @test */
    public function school_received_clears_stray_teacher_id(): void
    {
        [$teacher, $course] = $this->percentTeacher();
        $u1 = User::factory()->create();

        // received_account=school, но задан received_by_teacher_id — guard его чистит.
        $p = Payment::create([
            'course_id' => $course->id,
            'user_id' => $u1->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'received_by_teacher_id' => $teacher->id,
        ]);

        $this->assertNull($p->fresh()->received_by_teacher_id);
    }
}
