<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access\LessonGate;
use App\Services\DebtorsReport;
use App\Services\Payments\B2bGroupEntitlement;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2B-оплата за группу: «открыть доступ до оплаты» (payments.access_while_pending,
 * флаг features.b2b_group_pending_access поверх b2b_group_payment).
 *
 * Контракт:
 *  1. Флаг OFF — ожидающий групповой платёж доступа не даёт (как раньше).
 *  2. Флаг ON — ожидающий платёж с отметкой открывает ученикам группы свои блоки
 *     и снимает их из должников по этим блокам; без отметки — не открывает.
 *  3. Выручка и ЗП не считают ожидающий платёж; после «Оплачено» — считают.
 *  4. Отмена платежа закрывает доступ.
 *  5. Ежедневная чистка зависших оплат групповой платёж не трогает.
 */
class B2bGroupPendingAccessTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private User $student;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Moscow']);
        config(['features.b2b_group_payment' => true]);

        $teacher = Teacher::create(['name' => 'Преподаватель гр.64']);
        $this->course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-11-01']);
        CourseBlock::create(['course_id' => $this->course->id, 'number' => 2, 'is_active' => true, 'starts_at' => '2026-12-01']);

        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group->id);

        foreach ([1, 2] as $block) {
            Lesson::factory()->create([
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'block_number' => $block,
                'is_free' => false,
            ]);
        }

        $this->student = User::factory()->create();
        $this->payer = User::factory()->create();
        $this->student->groups()->attach($this->group->id);
    }

    /** Групповой платёж юрлица за блок 1, ещё не оплачен. */
    private function pendingBlockOne(bool $accessWhilePending = true, array $overrides = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create($overrides + [
            'user_id' => $this->payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'tariff' => Payment::TARIFF_B2B_GROUP,
            'start_block' => 1,
            'end_block' => 1,
            'amount' => 15000,
            'status' => 'pending',
            'is_conditional' => false,
            'access_while_pending' => $accessWhilePending,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'cash',
        ]))->fresh();
    }

    private function lesson(int $block): Lesson
    {
        return Lesson::query()
            ->where('course_id', $this->course->id)
            ->where('block_number', $block)
            ->firstOrFail();
    }

    /** @return list<string> */
    private function keys(User $user): array
    {
        return StudentController::getUserUnlockedTariffs($user->id, $this->course->slug);
    }

    private function canWatch(int $block): bool
    {
        return app(LessonGate::class)->canWatch($this->student->fresh(), $this->lesson($block));
    }

    /** @return list<int> */
    private function debtorIds(): array
    {
        return app(DebtorsReport::class)->query()
            ->where('course_id', $this->course->id)
            ->get()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function test_flag_off_pending_group_payment_grants_nothing(): void
    {
        $this->assertFalse(B2bGroupEntitlement::pendingAccessEnabled());
        $this->pendingBlockOne();

        $this->assertNotContains('block_1', $this->keys($this->student));
        $this->assertFalse($this->canWatch(1));
        $this->assertContains($this->student->id, $this->debtorIds());
    }

    public function test_flag_on_pending_payment_with_mark_opens_its_blocks_only(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $this->pendingBlockOne();

        $keys = $this->keys($this->student);
        $this->assertContains('block_1', $keys);
        $this->assertNotContains('block_2', $keys);
        $this->assertTrue($this->canWatch(1));
        $this->assertFalse($this->canWatch(2));
    }

    public function test_flag_on_pending_payment_without_mark_grants_nothing(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $this->pendingBlockOne(accessWhilePending: false);

        $this->assertFalse($this->canWatch(1));
    }

    public function test_covered_student_is_not_a_debtor_while_payment_is_pending(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $this->pendingBlockOne(overrides: ['start_block' => null, 'end_block' => null]);

        $this->assertNotContains($this->student->id, $this->debtorIds());
    }

    public function test_revenue_counts_group_payment_only_after_it_is_paid(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $payment = $this->pendingBlockOne();
        $service = app(TeacherSalaryService::class);

        $this->assertEqualsWithDelta(0.0, $service->blockGroupRevenue($this->course->id, 1, $this->group->id), 0.01);

        Payment::withoutEvents(fn () => $payment->update(['status' => 'paid']));
        $this->assertEqualsWithDelta(15000.0, $service->blockGroupRevenue($this->course->id, 1, $this->group->id), 0.01);
        $this->assertTrue($this->canWatch(1));
    }

    public function test_canceled_payment_closes_access(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $payment = $this->pendingBlockOne();
        $this->assertTrue($this->canWatch(1));

        Payment::withoutEvents(fn () => $payment->update(['status' => 'canceled']));
        $this->assertFalse($this->canWatch(1));
    }

    public function test_stale_checkout_reaper_leaves_group_payment_pending(): void
    {
        $payment = $this->pendingBlockOne();
        Payment::withoutEvents(fn () => Payment::query()->whereKey($payment->id)->update(['created_at' => now()->subDays(90)]));

        $this->artisan('payments:expire-stale-checkouts', ['--apply' => true])->assertSuccessful();

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
