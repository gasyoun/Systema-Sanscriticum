<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TeacherPayReceivedMail;
use App\Mail\TeacherPayStudentAckMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeacherPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        // Фича по умолчанию выключена — включаем на время тестов.
        config([
            'services.teacher_pay.enabled' => true,
            'services.admin.email' => 'admin@example.test',
        ]);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
    }

    private function teacher(): Teacher
    {
        return Teacher::factory()->create(['name' => 'Edgar Leitan']);
    }

    /** @test */
    public function disabled_feature_returns_404(): void
    {
        config(['services.teacher_pay.enabled' => false]);
        $tariff = $this->blockTariff();

        $this->get(route('teacherpay.claim.show', $tariff))->assertNotFound();
    }

    /** @test */
    public function enabled_form_renders_with_teacher_select(): void
    {
        $teacher = $this->teacher();
        $tariff = $this->blockTariff();

        $this->get(route('teacherpay.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Оплата напрямую преподавателю')
            ->assertSee('Сообщите об оплате')
            ->assertSee($teacher->name, false)
            ->assertSee('Отправитель перевода');
    }

    /** @test */
    public function guest_claim_creates_pending_teacher_payment_without_access(): void
    {
        $teacher = $this->teacher();
        $tariff = $this->blockTariff();

        $response = $this->post(route('teacherpay.claim.store', $tariff), [
            'name' => 'Valērijs Test',
            'email' => 'valerijs@example.test',
            'teacher_id' => $teacher->id,
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'VALĒRIJS BEINAROVIČS',
            'paid_on' => now()->toDateString(),
            'reference' => 'FT262308KY5X',
        ]);

        $response->assertRedirect();

        $payment = Payment::query()
            ->whereHas('user', fn ($q) => $q->where('email', 'valerijs@example.test'))
            ->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertTrue($payment->isTeacherTransfer());
        // Деньги на личный счёт преподавателя — сразу привязаны к получателю:
        // после подтверждения движок зарплаты вычтет номинал сам (H4597).
        $this->assertSame(Payment::RECEIVED_TEACHER, $payment->received_account);
        $this->assertSame($teacher->id, (int) $payment->received_by_teacher_id);
        $this->assertSame(70.0, (float) $payment->foreign_amount);
        $this->assertSame('EUR', $payment->foreign_currency);
        $this->assertSame('FT262308KY5X', $payment->claimMeta('reference'));
        $this->assertSame(4800.0, (float) $payment->amount);

        // Доступ НЕ открыт: pending ждёт сверки куратора.
        $this->assertFalse(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'Pending-заявка не должна открывать доступ.'
        );

        Mail::assertQueued(TeacherPayStudentAckMail::class);
        Mail::assertQueued(TeacherPayReceivedMail::class);
    }

    /** @test */
    public function existing_student_claim_stays_pending_until_curator_confirms(): void
    {
        // В отличие от школьного SEPA-канала авто-доверия НЕТ: каждую заявку
        // «прямо преподавателю» сверяет куратор (миссия H4627).
        $tariff = $this->blockTariff();
        $teacher = $this->teacher();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('teacherpay.claim.store', $tariff), [
                'teacher_id' => $teacher->id,
                'foreign_amount' => '70',
                'foreign_currency' => 'EUR',
                'sender_name' => 'STUDENT IBAN LT00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertRedirect();

        $payment = Payment::query()->where('provider', Payment::PROVIDER_TEACHER_TRANSFER)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->claimMeta('auto_trusted'));
    }

    /** @test */
    public function confirmation_grants_access_and_marks_payment_paid(): void
    {
        $tariff = $this->blockTariff();
        $teacher = $this->teacher();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('teacherpay.claim.store', $tariff), [
                'teacher_id' => $teacher->id,
                'foreign_amount' => '70',
                'foreign_currency' => 'EUR',
                'sender_name' => 'STUDENT IBAN LT00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertRedirect();

        /** @var Payment $payment */
        $payment = Payment::query()->where('provider', Payment::PROVIDER_TEACHER_TRANSFER)->firstOrFail();
        // Зеркало Filament-экшена «Подтвердить перевод преподавателю».
        $payment->update(['status' => 'paid']);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'Подтверждённая заявка открывает доступ штатным конвейером.'
        );
    }

    /** @test */
    public function teacher_is_required(): void
    {
        $tariff = $this->blockTariff();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->from(route('teacherpay.claim.show', $tariff))
            ->post(route('teacherpay.claim.store', $tariff), [
                'foreign_amount' => '70',
                'foreign_currency' => 'EUR',
                'sender_name' => 'SOMEONE',
                'paid_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('teacher_id');

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function future_paid_on_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        $teacher = $this->teacher();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->from(route('teacherpay.claim.show', $tariff))
            ->post(route('teacherpay.claim.store', $tariff), [
                'teacher_id' => $teacher->id,
                'foreign_amount' => '70',
                'foreign_currency' => 'EUR',
                'sender_name' => 'SOMEONE',
                'paid_on' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('paid_on');

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function guest_with_existing_email_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        $teacher = $this->teacher();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('teacherpay.claim.store', $tariff), [
            'name' => 'Someone',
            'email' => 'TAKEN@example.test',
            'teacher_id' => $teacher->id,
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'SOMEONE',
            'paid_on' => now()->toDateString(),
        ])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function teacher_claims_are_not_reaped_by_stale_checkout_reaper(): void
    {
        $tariff = $this->blockTariff();
        $teacher = $this->teacher();

        $this->post(route('teacherpay.claim.store', $tariff), [
            'name' => 'Pending Guest',
            'email' => 'guest-teacher@example.test',
            'teacher_id' => $teacher->id,
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'GUEST SENDER',
            'paid_on' => now()->toDateString(),
        ])->assertRedirect();

        $payment = Payment::query()->where('provider', Payment::PROVIDER_TEACHER_TRANSFER)->firstOrFail();
        $this->assertContains($payment->provider, Payment::MANUAL_CLAIM_PROVIDERS);
    }
}
