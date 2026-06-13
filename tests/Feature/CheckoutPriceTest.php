<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PaymentController::createPayment — точка, где собираются все механизмы цены
 * (скидка/лояльность -> кредиты -> промокод -> прана) и пишутся в Payment,
 * который дальше открывает доступ. Особо важна ветка нулевой цены: 100%-промокод
 * выдаёт «оплаченный» доступ БЕЗ обращения в Точку. До сих пор не покрыто.
 */
class CheckoutPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        // Точка не дёргается по-настоящему — отдаём фейковую платёжную ссылку.
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function tariff(int $price = 5000): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->create(['price' => $price]);
    }

    /** @test */
    public function fixed_promo_covering_full_price_grants_paid_access_without_tochka(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);

        $promo = PromoCode::create([
            'code' => 'FREE100',
            'type' => 'fixed',
            'value' => 5000,
            'is_active' => true,
        ]);

        // Редирект в кабинет (а не на платёжную ссылку) = сработала ветка нулевой
        // цены ДО вызова Точки.
        $this->actingAs($user)
            ->withSession(['promo_code' => $promo->code])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect(route('student.dashboard'));

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(0, (float) $payment->amount);

        // Промокод учтён и снят из сессии.
        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertFalse(session()->has('promo_code'));
    }

    /** @test */
    public function percent_promo_reduces_price_and_sends_pending_payment_to_tochka(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);

        $promo = PromoCode::create([
            'code' => 'MINUS20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['promo_code' => $promo->code])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(4000, (float) $payment->amount); // 5000 − 20%
        $this->assertSame(1, $promo->fresh()->used_count);
    }
}
