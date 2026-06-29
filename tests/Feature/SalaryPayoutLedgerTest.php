<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherPayoutPoster;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SalaryPayoutLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function makePayout(Course $course, Teacher $teacher, float $amount = 16008, ?int $block = 5): TeacherPayout
    {
        return $teacher->payouts()->create([
            'amount' => $amount,
            'paid_at' => '2026-05-10',
            'period_month' => '2026-05',
            'course_id' => $course->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'comment' => 'тест',
            'breakdown' => ['block_number' => $block],
        ]);
    }

    /** @test */
    public function poster_creates_linked_negative_transaction_with_course_and_block(): void
    {
        $teacher = Teacher::create(['name' => 'Екатерина']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $payout = $this->makePayout($course, $teacher);

        $payment = app(TeacherPayoutPoster::class)->post($payout);

        $this->assertNotNull($payment);
        $this->assertSame('salary_payout', $payment->tariff);
        $this->assertEqualsWithDelta(-16008.0, (float) $payment->amount, 0.01);
        $this->assertSame($course->id, $payment->course_id);
        $this->assertSame(5, $payment->start_block);
        $this->assertSame(5, $payment->end_block);
        $this->assertSame('paid', $payment->status);
        $this->assertSame($payment->id, $payout->fresh()->payment_id);
    }

    /** @test */
    public function posting_is_idempotent(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $payout = $this->makePayout($course, $teacher);

        $poster = app(TeacherPayoutPoster::class);
        $first = $poster->post($payout);
        $second = $poster->post($payout->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::where('tariff', 'salary_payout')->count());
    }

    /** @test */
    public function salary_payout_does_not_grant_access_or_enroll(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);
        $user = User::factory()->create();

        // Полноценное создание (с обсёрверами) — гард должен пропустить всё.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => -16008,
            'tariff' => 'salary_payout',
            'status' => 'paid',
            'start_block' => 5,
            'end_block' => 5,
        ]);

        $this->assertFalse(
            $user->fresh()->courses()->where('courses.id', $course->id)->exists(),
            'Выплата ЗП не должна записывать на курс.',
        );
        $this->assertFalse(
            $user->fresh()->groups()->where('groups.id', $group->id)->exists(),
            'Выплата ЗП не должна выдавать доступ к группе.',
        );
    }

    /** @test */
    public function salary_payout_is_excluded_from_accrual(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 10,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        $u1 = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $u1) {
            Payment::create([
                'user_id' => $u1->id, 'course_id' => $course->id, 'amount' => 1000,
                'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1, 'status' => 'paid',
            ]);
            // Транзакция выплаты на том же курсе не должна влиять на начисление.
            Payment::create([
                'user_id' => $u1->id, 'course_id' => $course->id, 'amount' => -500,
                'tariff' => 'salary_payout', 'start_block' => 1, 'end_block' => 1, 'status' => 'paid',
            ]);
        });

        $this->assertSame(100.0, app(TeacherSalaryService::class)->totalForTeacher($teacher->fresh('courses')));
    }

    /** @test */
    public function payout_report_email_renders_with_rate_date(): void
    {
        $teacher = Teacher::create(['name' => 'Екатерина', 'email' => 'kat@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);

        $payout = $teacher->payouts()->create([
            'amount' => 13248,
            'paid_at' => '2026-05-10',
            'period_month' => '2026-05',
            'course_id' => $course->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'payout_currency' => 'EUR',
            'exchange_rate' => 100.0,
            'rate_date' => '2026-05-08',
            'amount_foreign' => 132.48,
            'breakdown' => [
                'block_number' => 1,
                'student_count' => 2,
                'paid_student_count' => 2,
                'free_student_count' => 1,
            ],
        ]);

        // render() компилирует blade — ловит ParseError (inline @if после слова).
        $html = (new \App\Mail\TeacherPayoutReportMail($payout->id))->render();

        $this->assertStringContainsString('Курс PayPal на 08.05.2026', $html);
        $this->assertStringContainsString('132.48 €', $html);
        $this->assertStringContainsString('Всего студентов', $html);
        $this->assertStringContainsString('льготников', $html);
        // Всего 3 = платных 2 + льготников 1.
        $this->assertMatchesRegularExpression('/Всего студентов.*?3/s', $html);
        $this->assertMatchesRegularExpression('/льготников.*?1/s', $html);
    }

    /** @test */
    public function block_free_student_count_counts_only_zero_amount_real_payments(): void
    {
        $course = Course::factory()->create();
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Payment::withoutEvents(function () use ($course) {
            $paid = User::factory()->create();
            $free = User::factory()->create();
            $promised = User::factory()->create();

            // Платный — не льготник.
            Payment::create([
                'user_id' => $paid->id, 'course_id' => $course->id, 'amount' => 1000,
                'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1, 'status' => 'paid',
            ]);
            // Льготник — реальная нулевая оплата.
            Payment::create([
                'user_id' => $free->id, 'course_id' => $course->id, 'amount' => 0,
                'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1, 'status' => 'paid',
            ]);
            // Доступ «под честное слово» (conditional) — не льготник.
            Payment::create([
                'user_id' => $promised->id, 'course_id' => $course->id, 'amount' => 0,
                'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1, 'status' => 'paid',
                'is_conditional' => true,
            ]);
        });

        $free = app(TeacherSalaryService::class)->blockFreeStudentCount($course->id, 1, null);

        $this->assertSame(1, $free);
    }

    /** @test */
    public function payout_stores_and_casts_rate_date(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);

        $payout = $teacher->payouts()->create([
            'amount' => 16008,
            'paid_at' => '2026-05-10',
            'period_month' => '2026-05',
            'course_id' => $course->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'payout_currency' => 'EUR',
            'exchange_rate' => 100.0,
            'rate_date' => '2026-05-08',
            'amount_foreign' => 160.08,
        ]);

        $fresh = $payout->fresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->rate_date);
        $this->assertSame('2026-05-08', $fresh->rate_date->toDateString());
    }

    /** @test */
    public function deleting_payout_removes_linked_transaction(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $payout = $this->makePayout($course, $teacher);
        $payment = app(TeacherPayoutPoster::class)->post($payout);

        $payout->fresh()->delete();

        $this->assertNull(Payment::find($payment->id), 'Удаление выплаты должно удалить транзакцию-зеркало.');
    }

    /** @test */
    public function backfill_command_is_dry_run_by_default_and_idempotent_with_apply(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $this->makePayout($course, $teacher, 1000, 2);
        $this->makePayout($course, $teacher, 2000, 3);

        // Dry-run — ничего не создаёт.
        Artisan::call('salary:post-payouts');
        $this->assertSame(0, Payment::where('tariff', 'salary_payout')->count());
        $this->assertSame(2, TeacherPayout::whereNull('payment_id')->count());

        // --apply — создаёт для обеих.
        Artisan::call('salary:post-payouts', ['--apply' => true]);
        $this->assertSame(2, Payment::where('tariff', 'salary_payout')->count());
        $this->assertSame(0, TeacherPayout::whereNull('payment_id')->count());

        // Повторный --apply не дублирует.
        Artisan::call('salary:post-payouts', ['--apply' => true]);
        $this->assertSame(2, Payment::where('tariff', 'salary_payout')->count());
    }
}
