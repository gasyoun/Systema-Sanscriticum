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
    public function blocks_before_first_paid_block_are_not_counted_as_debt(): void
    {
        // Студент присоединился к потоку в середине: первый оплаченный блок №3.
        // Блоки №1–2 (прошли до его прихода) НЕ должны попадать в долг.
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->create(['number' => 2]);
        CourseBlock::factory()->for($course)->create(['number' => 3]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 4]);

        // Оплачен только блок №3; текущий блок №4 — не оплачен.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_3', 'status' => 'paid',
            'start_block' => 3, 'end_block' => 3,
        ]);

        $debt = app(StudentDebtsService::class)->forUser($user)->first();

        $this->assertNotNull($debt);
        $this->assertSame([4], $debt->debt_block_numbers, 'В долг попадает только №4; №1–2 (до входа) и №3 (оплачен) — нет.');
    }

    /** @test */
    public function explicit_joined_at_block_overrides_first_paid_block(): void
    {
        // Явный «блок входа» №2 в course_user имеет приоритет над авто-границей.
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->create(['number' => 2]);
        CourseBlock::factory()->for($course)->create(['number' => 3]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 4]);

        $user->courses()->attach($course->id, ['status' => 'Записался', 'joined_at_block' => 2]);

        // Оплачен только блок №3.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_3', 'status' => 'paid',
            'start_block' => 3, 'end_block' => 3,
        ]);

        $debt = app(StudentDebtsService::class)->forUser($user)->first();

        $this->assertNotNull($debt);
        $this->assertSame([2, 4], $debt->debt_block_numbers, 'Граница №2: №1 исключён, №2 и №4 — долг, №3 оплачен.');
    }

    /**
     * Курс из 3 блоков (№3 текущий), оплачен только №1 → без гейта был бы долг №2–3.
     */
    private function courseWithDebtOnBlocks2and3(User $user): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->create(['number' => 2]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 3]);

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
        ]);

        return $course;
    }

    /** @test */
    public function left_student_is_not_a_debtor(): void
    {
        // «Покинул» в course_user — долг в кабинете не начисляется, зеркально
        // админскому Debtors (DebtorsReport::NON_DEBT_STATUSES) и debts:remind.
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Покинул', 'left_after_block' => 1]]);

        $this->assertTrue(app(StudentDebtsService::class)->forUser($user)->isEmpty());
    }

    /** @test */
    public function graduate_status_is_not_a_debtor_either(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Выпускник']]);

        $this->assertTrue(app(StudentDebtsService::class)->forUser($user)->isEmpty());
    }

    /** @test */
    public function left_student_with_unmet_promise_is_not_a_debtor(): void
    {
        // Даже непогашенное обещание не делает ушедшего должником — админский
        // контур (Debtors, debts:remind) таких тоже не считает.
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Покинул']]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->assertTrue(app(StudentDebtsService::class)->forUser($user)->isEmpty());
    }

    /** @test */
    public function left_after_block_caps_debt_even_with_active_status(): void
    {
        // «Блок выхода» — потолок долга и при неснятом активном статусе:
        // хелпер-текст админки обещает «долги по более поздним блокам не
        // начисляются». Оплачено ровно до блока выхода → долга нет.
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Записался', 'left_after_block' => 1]]);

        $this->assertTrue(app(StudentDebtsService::class)->forUser($user)->isEmpty());
    }

    /** @test */
    public function left_after_block_keeps_debt_below_the_cap(): void
    {
        // Вышел после №2, но №2 не оплачен → долг ровно [2], без №3.
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Записался', 'left_after_block' => 2]]);

        $debt = app(StudentDebtsService::class)->forUser($user)->first();

        $this->assertNotNull($debt);
        $this->assertSame([2], $debt->debt_block_numbers);
    }

    /** @test */
    public function active_status_without_exit_block_still_owes(): void
    {
        // Регресс: обычный активный студент с непокрытым текущим блоком —
        // долг как раньше.
        $user = User::factory()->create();
        $course = $this->courseWithDebtOnBlocks2and3($user);

        $user->courses()->syncWithoutDetaching([$course->id => ['status' => 'Записался']]);

        $debt = app(StudentDebtsService::class)->forUser($user)->first();

        $this->assertNotNull($debt);
        $this->assertSame([2, 3], $debt->debt_block_numbers);
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

    /** @test */
    public function paid_until_stops_at_gap_but_reports_later_paid_blocks(): void
    {
        // Реальный кейс: блок №64 пропущен целиком (не оплачен и оплачиваться
        // не будет), оплаты возобновились с №65. «Оплачено до» честно
        // останавливается на №63, но отдельно оплаченный №65 не должен
        // исчезнуть из сводки.
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        foreach ([62, 63] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }
        $gapStarts = now()->addWeek()->startOfDay();
        CourseBlock::factory()->for($course)->create(['number' => 64, 'starts_at' => $gapStarts]);
        foreach ([65, 66] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        // Цепочка: №62–63 оплачены одним платежом.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_62', 'status' => 'paid',
            'start_block' => 62, 'end_block' => 63,
        ]);
        // №64 — разрыв; оплата возобновилась с №65.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 3000, 'tariff' => 'block_65', 'status' => 'paid',
            'start_block' => 65, 'end_block' => 65,
        ]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNotNull($paidUntil);
        $this->assertSame(63, (int) $paidUntil->block->number, 'Непрерывная цепочка заканчивается на №63.');
        $this->assertSame(64, (int) $paidUntil->next_block->number, 'Следующий к оплате — именно пропущенный №64.');
        $this->assertEquals($gapStarts, $paidUntil->next_payment_deadline);
        $this->assertSame([65], $paidUntil->extra_paid_blocks, '№65 оплачен после разрыва и должен попасть в extra_paid_blocks.');
        $this->assertSame('№65', $paidUntil->extra_paid_blocks_label);
        $this->assertSame(7000.0, $paidUntil->amount_paid, 'Сумма считается по всем реальным оплатам, включая «островок» после разрыва.');
    }

    /** @test */
    public function paid_until_without_gap_has_no_extra_paid_blocks(): void
    {
        // Непрерывная оплата без разрывов: extra_paid_blocks пуст, label null —
        // прежнее поведение сводки не меняется.
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        foreach ([1, 2, 3] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 12000, 'tariff' => 'full', 'status' => 'paid',
        ]);

        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$course->id])
            ->get($course->id);

        $this->assertNotNull($paidUntil);
        $this->assertSame(3, (int) $paidUntil->block->number);
        $this->assertNull($paidUntil->next_block);
        $this->assertSame([], $paidUntil->extra_paid_blocks);
        $this->assertNull($paidUntil->extra_paid_blocks_label);
    }
}
