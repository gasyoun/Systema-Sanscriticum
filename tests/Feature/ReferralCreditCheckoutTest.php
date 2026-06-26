<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Реферальный денежный кредит в чекауте: авто-зачёт в стоимость, списание из
 * кошелька в той же транзакции, возврат при срыве оплаты.
 */
class ReferralCreditCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_001'],
            ], 200),
        ]);
    }

    private function tariff(int $price = 5000): Tariff
    {
        return Tariff::factory()->for(Course::factory()->create())->create(['price' => $price]);
    }

    /** @test */
    public function partial_credit_reduces_price_and_is_debited_from_wallet(): void
    {
        $user = User::factory()->create(['referral_credit' => 2000]);
        $tariff = $this->tariff(5000);

        $this->actingAs($user)->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame(3000.0, (float) $payment->amount);                  // 5000 − 2000
        $this->assertSame(2000.0, (float) $payment->referral_credit_applied);
        $this->assertSame(0.0, (float) $user->fresh()->referral_credit);      // кошелёк списан
    }

    /** @test */
    public function credit_covering_full_price_grants_paid_access_without_tochka(): void
    {
        $user = User::factory()->create(['referral_credit' => 6000]);
        $tariff = $this->tariff(5000);

        $this->actingAs($user)->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect(route('student.dashboard'));

        $payment = Payment::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame(0.0, (float) $payment->amount);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(5000.0, (float) $payment->referral_credit_applied); // зачтено только сколько нужно
        $this->assertSame(1000.0, (float) $user->fresh()->referral_credit);   // 6000 − 5000 остаётся
        Http::assertNothingSent();
    }

    /** @test */
    public function failed_payment_refunds_applied_credit_to_wallet(): void
    {
        $user = User::factory()->create(['referral_credit' => 0]);
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 3000,
            'referral_credit_applied' => 2000,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $payment->update(['status' => 'failed']); // booted::updated → refund

        $this->assertSame(2000.0, (float) $user->fresh()->referral_credit);
        $this->assertSame(0.0, (float) $payment->fresh()->referral_credit_applied); // не вернётся дважды
    }
}
