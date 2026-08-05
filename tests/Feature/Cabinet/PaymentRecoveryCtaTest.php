<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2060 — Noboring dozhim wave-1 H-C: cabinet recovery + installment/curator
 * CTA copy on student.access, behind payment_recovery_cta. Pure presentation
 * on top of the already-existing DebtPaymentResolver/StudentDebtsService —
 * does not touch PaymentObserver grant paths.
 */
class PaymentRecoveryCtaTest extends TestCase
{
    use RefreshDatabase;

    private function studentWithOverdueInstallmentDebt(): array
    {
        config(['features.cabinet_hybrid' => true]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика I']);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);

        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        // Sanity: StudentDebtsService actually surfaces this course as a debt.
        $debts = app(StudentDebtsService::class)->forUser($user);
        $this->assertTrue($debts->contains('course_id', $course->id));

        return compact('user', 'course');
    }

    /** @test */
    public function recovery_cta_block_hidden_when_flag_off(): void
    {
        ['user' => $user] = $this->studentWithOverdueInstallmentDebt();
        config(['features.payment_recovery_cta' => false]);

        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertDontSee('payment-recovery-cta', false)
            ->assertDontSee('Написать куратору', false)
            ->assertDontSee(route('faq.payment'), false);
    }

    /** @test */
    public function recovery_cta_block_visible_when_flag_on(): void
    {
        ['user' => $user] = $this->studentWithOverdueInstallmentDebt();
        config(['features.payment_recovery_cta' => true]);

        $this->actingAs($user)
            ->get(route('student.access'))
            ->assertOk()
            ->assertSee('payment-recovery-cta', false)
            ->assertSee('рассрочку', false)
            ->assertSee('Написать куратору', false)
            ->assertSee(route('faq.payment'), false)
            ->assertSee('2', false); // amount fragment (2 000 ₽), loosely
    }

    /** @test */
    public function faq_payment_page_renders_publicly_without_login(): void
    {
        $this->get(route('faq.payment'))
            ->assertOk()
            ->assertSee('Оплата курса', false)
            ->assertSee('рассрочку', false)
            ->assertSee('https://t.me/rusamskrtam', false);
    }
}
