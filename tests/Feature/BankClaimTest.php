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

    /**
     * H5083: auto-trust требует «дозагрузочный» аккаунт — заводим с created_at
     * в прошлом (окно bootstrap по умолчанию 24ч, см. ClaimTrustPolicy).
     */
    private function agedStudent(array $attributes = [], int $days = 30): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

        return $user;
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
        // H5083: «существующий» = дозагрузочный аккаунт (не mint этой сессии).
        $student = $this->agedStudent(['email' => 'student@example.test']);

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
    public function self_minted_session_second_post_stays_pending(): void
    {
        // H5083 — зеркало PayPal-регрессии H5046: resolveUser() mintит аккаунт
        // и логинит его в той же сессии; ВТОРОЙ POST той же сессии раньше
        // проходил по auth()->check() как auto-trusted paid. Оба — pending.
        config(['services.bank_claim.trust_existing_students' => true]);
        $tariff = $this->blockTariff();

        // POST #1 — гость с новым email: аккаунт создан и залогинен в сессии.
        $this->post(route('bank.claim.store', $tariff), [
            'name' => 'Self Minted',
            'email' => 'self-minted-bank@example.test',
            'foreign_amount' => '90',
            'foreign_currency' => 'EUR',
            'sender_name' => 'SELF MINTED IBAN LT00',
            'paid_on' => now()->toDateString(),
        ])->assertRedirect(route('bank.claim.show', $tariff));

        // POST #2 — та же сессия, пользователь уже залогинен после #1.
        $second = $this->post(route('bank.claim.store', $tariff), [
            'foreign_amount' => '70',
            'foreign_currency' => 'EUR',
            'sender_name' => 'SELF MINTED IBAN LT00',
            'paid_on' => now()->toDateString(),
        ]);

        $second->assertRedirect(route('bank.claim.show', $tariff));
        $second->assertSessionHas('success', fn ($v) => str_contains((string) $v, 'Мы сверим'));

        $this->assertSame(
            2,
            Payment::query()->where('provider', Payment::PROVIDER_BANK_SEPA)->count(),
        );

        Payment::query()->where('provider', Payment::PROVIDER_BANK_SEPA)
            ->each(fn (Payment $payment) => $this->assertSame('pending', $payment->status));

        $minted = User::query()->where('email', 'self-minted-bank@example.test')->firstOrFail();
        $this->assertSame(
            0,
            $minted->courses()->count(),
            'Self-minted сессия не открывает доступ ни на каком POST.',
        );
        $this->assertSame(
            0,
            Payment::query()->where('user_id', $minted->id)->whereNotNull('claim_meta->trusted_at')->count(),
        );
    }

    /** @test */
    public function fresh_account_with_prior_paid_payment_is_trusted(): void
    {
        // H5083: второй путь доверия — проверенный денежный контур: свежий
        // аккаунт с PAID-платежом (не bank-claim, чтобы выборка ниже была
        // однозначной) trusted.
        config(['services.bank_claim.trust_existing_students' => true]);
        $tariff = $this->blockTariff();
        $student = User::factory()->create();
        Payment::create([
            'user_id' => $student->id,
            'course_id' => $tariff->course_id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'provider' => Payment::PROVIDER_INVOICE,
        ]);

        $this->actingAs($student)->post(route('bank.claim.store', $tariff), [
            'foreign_amount' => '90',
            'foreign_currency' => 'EUR',
            'sender_name' => 'STUDENT IBAN LT00',
            'paid_on' => now()->toDateString(),
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_BANK_SEPA)->latest()->firstOrFail();

        $this->assertSame('paid', $payment->status);
        $this->assertTrue($payment->isAutoTrustedBankClaim());
        $this->assertNotNull($payment->claimMeta('trusted_at'));
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
