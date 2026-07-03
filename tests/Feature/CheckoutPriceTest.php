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

        // used_count растёт только по подтверждённой оплате, а не на создании
        // pending — иначе брошенные чекауты исчерпывали бы лимит промокода.
        $this->assertSame(0, $promo->fresh()->used_count);

        // После оплаты (вебхук Точки → paid) код засчитывается ровно один раз.
        $payment->update(['status' => 'paid']);
        $this->assertSame(1, $promo->fresh()->used_count);
    }

    /** @test */
    public function prepaid_credit_is_reserved_for_one_pending_checkout_only(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);

        $deposit = Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $first = Payment::query()->where('tariff', 'full')->latest('id')->firstOrFail();
        $this->assertEquals(4000, (float) $first->amount);
        $this->assertSame([$deposit->id], $first->prepaid_credit_payment_ids);
        $this->assertSame($first->id, $deposit->fresh()->prepaid_reserved_by_payment_id);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $second = Payment::query()->where('tariff', 'full')->latest('id')->firstOrFail();
        $this->assertEquals(5000, (float) $second->amount);
        $this->assertNull($second->prepaid_credit_payment_ids);

        $first->update(['status' => 'paid']);
        $this->assertNotNull($deposit->fresh()->deposit_consumed_at);
    }

    /** @test */
    public function failed_pending_payment_releases_prepaid_reservation(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);

        $deposit = Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $payment = Payment::query()->where('tariff', 'full')->latest('id')->firstOrFail();
        $this->assertSame($payment->id, $deposit->fresh()->prepaid_reserved_by_payment_id);

        $payment->update(['status' => 'failed']);
        $this->assertNull($deposit->fresh()->prepaid_reserved_by_payment_id);
    }

    /** @test */
    public function stale_prepaid_reservation_is_released_and_old_payment_becomes_invalid(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);

        $deposit = Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $old = Payment::query()->where('tariff', 'full')->latest('id')->firstOrFail();
        $old->updateQuietly(['created_at' => now()->subMinutes(31)]);

        Payment::releaseStalePrepaidReservations($user->id, $tariff->course_id);

        $this->assertNull($deposit->fresh()->prepaid_reserved_by_payment_id);
        $this->assertFalse($old->fresh()->hasValidPrepaidReservations());
    }
}
