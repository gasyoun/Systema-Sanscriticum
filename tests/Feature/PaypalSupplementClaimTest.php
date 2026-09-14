<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaypalClaimStudentAckMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaypalSupplementClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');

        config([
            'services.paypal.enabled' => true,
            'services.paypal.me_link' => 'https://www.paypal.com/paypalme/gasuns',
            'services.admin.email' => 'admin@example.test',
        ]);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(3)->create(['price' => 8000]);
    }

    private function supplementInvoice(User $user, Tariff $tariff): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 2000.00,
            'tariff' => '8000.00',
            'status' => 'pending',
            'provider' => 'manual',
            'payer_note' => 'Доплата за блок 3: внесено 6000 из 8000',
        ]);
    }

    /** @test */
    public function supplement_form_renders_fixed_price_and_prefilled_me_link(): void
    {
        $tariff = $this->blockTariff();

        $this->get(route('paypal.claim.supplement', $tariff))
            ->assertOk()
            ->assertSee('Доплата за блок через PayPal')
            ->assertSee('22 €', false)
            ->assertSee('26 $', false)
            ->assertSee('https://www.paypal.com/paypalme/gasuns/22', false)
            ->assertSee('supplement_mode', false);
    }

    /** @test */
    public function wrong_amount_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'supplement_mode' => '1',
            'foreign_amount' => 90,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-09-01',
        ])->assertSessionHasErrors('foreign_amount');

        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    /** @test */
    public function trusted_student_with_open_invoice_closes_it_without_creating_block_row(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->supplementInvoice($user, $tariff);

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'supplement_mode' => '1',
            'foreign_amount' => 22,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-09-01',
            'paypal_txn' => 'TXSUP',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(22.0, (float) $invoice->foreign_amount);
        $this->assertTrue($invoice->claim_meta['supplement']);

        // Никакой новой строки полной цены блока не появилось.
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Payment::where('amount', 8000.0)->count());

        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    /** @test */
    public function without_open_invoice_creates_pending_supplement_row_never_trusted_paid(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'supplement_mode' => '1',
            'foreign_amount' => 26,
            'foreign_currency' => 'USD',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-09-01',
        ])->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(2000.0, (float) $payment->amount);
        $this->assertSame(26.0, (float) $payment->foreign_amount);
        $this->assertStringContainsString('доплата', mb_strtolower($payment->payer_note));
    }

    /** @test */
    public function supplement_via_normal_form_without_flag_keeps_full_price_path(): void
    {
        // Обычная форма (без supplement_mode) НЕ должна закрывать инвойс-доплату —
        // полная цена блока по-прежнему создаёт свою строку.
        $tariff = $this->blockTariff();
        $user = User::factory()->create();
        $invoice = $this->supplementInvoice($user, $tariff);

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 90,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-09-01',
        ])->assertRedirect();

        $this->assertSame(2, Payment::count());
        $this->assertSame('pending', $invoice->fresh()->status);
    }
}
