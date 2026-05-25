<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentDebtsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Курс с блоками №1 и №2, где №2 — текущий (reference). Возвращает курс.
     */
    private function courseWithCurrentBlock2(): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);

        return $course;
    }

    /** @test */
    public function installment_plan_without_real_payment_shows_as_debt_with_full_schedule(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $group = (string) Str::uuid();
        // Один платёж просрочен (срок прошёл), два — впереди.
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonths(2)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertCount(1, $debts, 'Курс с рассрочкой без реальной оплаты должен попасть в долги.');
        $debt = $debts->first();

        $this->assertTrue($debt->is_installment);
        $this->assertTrue($debt->has_arrangement);
        $this->assertFalse($debt->has_real_payment);
        $this->assertTrue($debt->promise_overdue, 'Есть платёж с прошедшим сроком → курс просрочен.');
        $this->assertSame(1, $debt->overdue_count);
        $this->assertCount(3, $debt->promises, 'В графике должны быть все 3 платежа.');
        $this->assertSame(6000.0, $debt->plan_remaining, 'Осталось внести = сумма неоплаченных обещаний.');
    }

    /** @test */
    public function installment_on_finished_course_without_reference_block_still_shows(): void
    {
        // Завершённый поток: блоки без дат и без is_current → reference-блока нет.
        // Раньше такой курс отсекался и рассрочка не показывалась (прод-баг).
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1, 'is_current' => false, 'starts_at' => null, 'ends_at' => null]);
        CourseBlock::factory()->for($course)->create(['number' => 2, 'is_current' => false, 'starts_at' => null, 'ends_at' => null]);

        $group = (string) Str::uuid();
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonths(2)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertCount(1, $debts, 'Рассрочка на завершённом курсе без reference-блока всё равно должна быть долгом.');
        $debt = $debts->first();
        $this->assertNull($debt->ref_block, 'У такого курса нет текущего блока.');
        $this->assertTrue($debt->is_installment);
        $this->assertSame(4000.0, $debt->plan_remaining);
        $this->assertCount(2, $debt->promises);
    }

    /** @test */
    public function expired_promise_is_treated_as_overdue(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        // Демон promises:expire уже перевёл обещание в expired.
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subWeek()->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertCount(1, $debts);
        $debt = $debts->first();
        $this->assertTrue($debt->has_arrangement);
        $this->assertTrue($debt->promise_overdue);
        $this->assertFalse($debt->is_installment, 'Одиночное обещание — не рассрочка.');
    }

    /** @test */
    public function fulfilled_and_cancelled_promises_alone_are_not_a_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subMonth()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_FULFILLED,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_CANCELLED,
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertTrue($debts->isEmpty(), 'Выполненные/отменённые обещания без неоплаченного остатка долгом не считаются.');
    }

    /** @test */
    public function not_renewed_real_payment_shows_without_arrangement(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        // Реальная оплата покрывает только блок №1, текущий блок №2 — не оплачен.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertCount(1, $debts);
        $debt = $debts->first();
        $this->assertTrue($debt->has_real_payment);
        $this->assertFalse($debt->has_arrangement);
        $this->assertFalse($debt->is_installment);
        $this->assertSame([2], $debt->debt_block_numbers);
    }

    /** @test */
    public function fully_paid_course_without_arrangement_is_not_a_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        // Полная оплата (NULL,NULL = весь курс) покрывает текущий блок.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 12000, 'tariff' => 'full', 'status' => 'paid',
        ]);

        $debts = app(StudentDebtsService::class)->forUser($user);

        $this->assertTrue($debts->isEmpty());
    }
}
