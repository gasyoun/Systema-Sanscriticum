<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaypalClaimReceivedMail;
use App\Mail\StudentWelcomeMail;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaypalClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        MarketingSetting::flushCached();

        // Фича по умолчанию выключена — включаем на время тестов.
        config([
            'services.paypal.enabled' => true,
            'services.paypal.me_link' => 'https://www.paypal.com/paypalme/school',
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
        config(['services.paypal.enabled' => false]);
        $tariff = $this->blockTariff();

        $this->get(route('paypal.claim.show', $tariff))->assertNotFound();
    }

    /** @test */
    public function enabled_form_renders(): void
    {
        $tariff = $this->blockTariff();

        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Оплата через PayPal')
            ->assertSee('Сообщите нам об оплате');
    }

    /** @test */
    public function guest_claim_creates_pending_paypal_payment_without_access(): void
    {
        $tariff = $this->blockTariff();

        $response = $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'foreign_currency' => 'USD',
            'paypal_txn' => '1AB23456CD789012E',
            'proof' => UploadedFile::fake()->image('receipt.png'),
            'comment' => 'Оплатил из США',
        ]);

        $response->assertRedirect(route('paypal.claim.show', $tariff));
        $response->assertSessionHas('success');

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, $payment->start_block);
        $this->assertSame(4800.0, (float) $payment->amount, 'Рублёвый номинал = цена тарифа.');
        $this->assertSame(50.0, (float) $payment->foreign_amount);
        $this->assertSame('USD', $payment->foreign_currency);
        $this->assertNotNull($payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);

        // pending НЕ открывает доступ.
        $this->assertSame(0, $payment->user->groups()->count());
        $this->assertFalse($payment->user->courses()->where('courses.id', $tariff->course_id)->exists());

        // Уведомление админу отправлено.
        Mail::assertQueued(PaypalClaimReceivedMail::class);
    }

    /** @test */
    public function requires_txn_or_proof(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'foreign_currency' => 'USD',
            // ни paypal_txn, ни proof
        ])->assertSessionHasErrors('paypal_txn');

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function guest_with_existing_email_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'taken@example.test',
            'foreign_amount' => 50,
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function admin_confirmation_grants_access_and_sends_welcome(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        // Админ сверил и подтвердил (как кнопка «Подтвердить PayPal» в Filament).
        $payment->update(['status' => 'paid']);

        // Доступ открылся штатной логикой Payment::booted() → запись на курс.
        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'После подтверждения студент должен быть записан на курс.'
        );

        // Первая оплата → welcome-письмо с паролем.
        Mail::assertQueued(StudentWelcomeMail::class, fn ($mail) => $mail->hasTo('john@example.test'));
    }

    /** @test */
    public function logged_in_student_does_not_need_name_email(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TX-EUR',
        ])->assertRedirect(route('paypal.claim.show', $tariff));

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame('EUR', $payment->foreign_currency);
    }
}
