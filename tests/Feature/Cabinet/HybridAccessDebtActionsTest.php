<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Гибридная «Оплата и доступ» недобирала два действия, которые классическая
 * вкладка «Мои долги» умеет: «Погасить всё» по графику и «Перенести дату»
 * (один раз, ≤14 дней). Оба добавлены за флагом
 * features.hybrid_access_debt_actions (default OFF).
 *
 * Пока флаг OFF — разметка страницы остаётся прежней (ни переноса даты, ни
 * кнопки «Погасить всё»). Формы — только POST: pay-all это POST-роут, голую
 * ссылку он не примет.
 */
class HybridAccessDebtActionsTest extends TestCase
{
    use RefreshDatabase;

    private function overdueInstallment(): array
    {
        config(['features.cabinet_hybrid' => true]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Медленное чтение']);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);

        $group = (string) Str::uuid();
        $first = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        // Sanity: сервис действительно видит долг по этому курсу.
        $this->assertTrue(
            app(StudentDebtsService::class)->forUser($user)->contains('course_id', $course->id)
        );

        return compact('user', 'course', 'first');
    }

    /** @test */
    public function flag_off_keeps_the_previous_markup(): void
    {
        ['user' => $user, 'course' => $course] = $this->overdueInstallment();
        config(['features.hybrid_access_debt_actions' => false]);

        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertDontSee('Перенести дату', false)
            ->assertDontSee('debt-reschedule', false)
            ->assertDontSee('debt-pay-all', false)
            ->assertDontSee(route('student.debt.course.pay-all', $course), false);
    }

    /** @test */
    public function flag_on_renders_pay_all_and_reschedule(): void
    {
        ['user' => $user, 'course' => $course, 'first' => $first] = $this->overdueInstallment();
        config(['features.hybrid_access_debt_actions' => true]);

        $html = $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->getContent();

        // «Погасить всё» — POST-форма на pay-all (не голая ссылка).
        $this->assertStringContainsString('data-testid="debt-pay-all"', $html);
        $this->assertStringContainsString('action="'.route('student.debt.course.pay-all', $course).'"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        // «Перенести дату» — форма на reschedule с полем даты.
        $this->assertStringContainsString('Перенести дату', $html);
        $this->assertStringContainsString('data-testid="debt-reschedule"', $html);
        $this->assertStringContainsString('action="'.route('student.debt.promise.reschedule', $first).'"', $html);
        $this->assertStringContainsString('name="new_date"', $html);
    }

    /** @test */
    public function flag_on_reschedule_post_moves_the_date_from_the_access_page(): void
    {
        ['user' => $user, 'first' => $first] = $this->overdueInstallment();
        config(['features.hybrid_access_debt_actions' => true]);

        $newDate = now()->addDays(5)->toDateString();

        $this->actingAs($user)
            ->from(route('student.access'))
            ->post(route('student.debt.promise.reschedule', $first), ['new_date' => $newDate])
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('success');

        $fresh = $first->fresh();
        $this->assertNotNull($fresh->student_rescheduled_at);
        $this->assertSame($newDate, $fresh->promised_at->toDateString());
    }

    /** @test */
    public function reschedule_disappears_once_the_student_already_used_it(): void
    {
        ['user' => $user, 'first' => $first] = $this->overdueInstallment();
        config(['features.hybrid_access_debt_actions' => true]);
        $first->forceFill(['student_rescheduled_at' => now()->subDay()])->save();

        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertDontSee('Перенести дату', false)
            ->assertDontSee('debt-reschedule', false);
    }
}
