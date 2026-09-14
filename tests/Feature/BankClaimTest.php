<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BankClaimReceivedMail;
use App\Mail\BankClaimStudentAckMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BankClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        // Фича по умолчанию выключена — включаем на время тестов.
        config([
            'services.bank_claim.enabled' => true,
            'services.bank_claim.recipient_name' => 'Edgar Leitan',
            'services.bank_claim.iban' => 'AT31 1100 0120 2558 9800',
            'services.admin.email' => 'admin@example.test',
        ]);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
    }

    /** @test */
    public function disabled_feature_returns_404(): void
    {
        config(['services.bank_claim.enabled' => false]);
        $tariff = $this->blockTariff();

        $this->get(route('bank.claim.show', $tariff))->assertNotFound();
    }

    /** @test */
    public function enabled_form_renders_with_recipient_details(): void
    {
        $tariff = $this->blockTariff();

        $this->get(route('bank.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Уведомление об оплате банковским переводом')
            ->assertSee('Сообщите нам об оплате')
            ->assertSee('AT31 1100 0120 2558 9800', false)
            ->assertSee('Отправитель перевода');
    }

    /** @test */
    public function guest_claim_creates_pending_payment_without_access(): void
    {
        $tariff = $this->blockTariff();

        $response = $this->post(route('bank.claim.store', $tariff), [
            'name' => 'Valērijs Test',
            'email' => 'valerijs@example.test',
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
        $this->assertTrue($payment->isBankSepa());
        $this->assertSame(70.0, (float) $payment->foreign_amount);
        $this->assertSame('EUR', $payment->foreign_currency);
        $this->assertSame('FT262308KY5X', $payment->claimMeta('reference'));
        $this->assertSame(4800.0, (float) $payment->amount);

        Mail::assertQueued(BankClaimStudentAckMail::class);
        Mail::assertQueued(BankClaimReceivedMail::class);
    }

    /** @test */
    public function trusted_existing_student_gets_paid_immediately(): void
    {
        config(['services.bank_claim.trust_existing_students' => true]);
        $tariff = $this->blockTariff();
        $student = User::factory()->create(['email' => 'student@example.test']);

        $response = $this->actingAs($student)->post(route('bank.claim.store', $tariff), [
            'foreign_amount' => '90',
            'foreign_currency' => 'EUR',
            'sender_name' => 'STUDENT IBAN LT00',
            'paid_on' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('bank.claim.show', $tariff));
        $response->assertSessionHas('success');

        /** @var Payment $payment */
        $payment = Payment::query()->where('provider', Payment::PROVIDER_BANK_SEPA)->latest()->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertTrue($payment->isAutoTrustedBankClaim());
        $this->assertNotNull($payment->claimMeta('trusted_at'));

        // Штатный конвейер отработал без ручного шага: студент записан на курс.
        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'Авто-доверенная заявка должна открыть доступ немедленно.'
        );

        Mail::assertQueued(BankClaimStudentAckMail::class, fn ($mail) => $mail->hasTo('student@example.test'));
        Mail::assertQueued(BankClaimReceivedMail::class);
    }

    /** @test */
    public function future_paid_on_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        $student = User::factory()->create();

        $this->actingAs($student)
            ->from(route('bank.claim.show', $tariff))
            ->post(route('bank.claim.store', $tariff), [
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
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('bank.claim.store', $tariff), [
            'name' => 'Someone',
            'email' => 'TAKEN@example.test',
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'SOMEONE',
            'paid_on' => now()->toDateString(),
        ])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function sepa_claims_are_not_reaped_by_stale_checkout_reaper(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('bank.claim.store', $tariff), [
            'name' => 'Pending Guest',
            'email' => 'guest-sepa@example.test',
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'GUEST SENDER',
            'paid_on' => now()->toDateString(),
        ])->assertRedirect();

        $payment = Payment::query()->where('provider', Payment::PROVIDER_BANK_SEPA)->firstOrFail();
        $this->assertContains($payment->provider, Payment::MANUAL_CLAIM_PROVIDERS);
    }
}
