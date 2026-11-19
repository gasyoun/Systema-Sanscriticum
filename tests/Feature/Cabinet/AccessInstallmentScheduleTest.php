<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «Оплата и доступ» (гибридный кабинет): по курсу с рассрочкой студент видит
 * весь график платежей — как во вкладке «Мои долги» классического кабинета.
 * Раньше здесь была только кнопка «Внести платеж» без дат и сумм.
 */
class AccessInstallmentScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 12:00:00');
        config(['features.cabinet_hybrid' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_installment_shows_full_schedule_with_statuses_and_remaining(): void
    {
        [$user, $course] = $this->studentWithCourse();
        $group = (string) Str::uuid();

        $this->promise($user, $course, '2026-10-01', 5000, PaymentPromise::STATUS_FULFILLED, $group);
        $this->promise($user, $course, '2026-11-01', 5000, PaymentPromise::STATUS_ACTIVE, $group);
        $this->promise($user, $course, '2026-12-01', 4000, PaymentPromise::STATUS_ACTIVE, $group);
        $this->promise($user, $course, '2026-12-15', 7777, PaymentPromise::STATUS_CANCELLED, $group);

        $html = $this->actingAs($user)->get(route('student.access'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="installment-schedule"', $html);

        foreach (['01.10.2026', '01.11.2026', '01.12.2026'] as $date) {
            $this->assertStringContainsString($date, $html);
        }
        $this->assertStringContainsString('5 000 ₽', $html);
        $this->assertStringContainsString('4 000 ₽', $html);

        $this->assertStringContainsString('Просрочено взносов: 1', $html);
        $this->assertSame(1, substr_count($html, 'data-status="paid"'));
        $this->assertSame(1, substr_count($html, 'data-status="overdue"'));
        $this->assertSame(1, substr_count($html, 'data-status="upcoming"'));

        // Остаток = два неоплаченных взноса; отменённый в график не попадает.
        $this->assertStringContainsString('Осталось внести', $html);
        $this->assertStringContainsString('9 000 ₽', $html);
        $this->assertStringNotContainsString('15.12.2026', $html);
        $this->assertStringNotContainsString('7 777', $html);

        $this->assertStringContainsString('Внести платеж', $html);
    }

    public function test_single_promise_has_no_schedule_block(): void
    {
        [$user, $course] = $this->studentWithCourse();

        $this->promise($user, $course, '2026-11-20', 4800, PaymentPromise::STATUS_ACTIVE, null);

        $html = $this->actingAs($user)->get(route('student.access'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="installment-schedule"', $html);
        $this->assertStringContainsString('Внести платеж', $html);
    }

    /** @return array{0: User, 1: Course} */
    private function studentWithCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Медленное чтение']);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);

        return [$user, $course];
    }

    private function promise(User $user, Course $course, string $date, int $amount, string $status, ?string $group): PaymentPromise
    {
        return PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => $date,
            'amount' => $amount,
            'status' => $status,
            'installment_group_id' => $group,
        ]);
    }
}
