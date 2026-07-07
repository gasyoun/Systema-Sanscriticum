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

    /**
     * money-core H071 #2: депозит числится unconsumed до момента реальной оплаты
     * (deposit_consumed_at ставится в consumeDepositsForCourse только по paid),
     * так что раньше второй pending-заказ на тот же курс получал СВОЙ полный
     * вычет того же ещё не потраченного депозита — оба заказа затем оплачивались
     * по заниженной цене, и депозит фактически списывался дважды. Сценарий из
     * аудита: 2000₽ депозит, два блока по 5000₽ → должно быть заблокировано
     * создание второго pending, пока первый висит неоплаченным.
     */
    /** @test */
    public function second_pending_order_on_same_course_is_blocked_while_deposit_unspent(): void
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $block1 = Tariff::factory()->for($course)->block(1)->create(['price' => 5000]);
        $block2 = Tariff::factory()->for($course)->block(2)->create(['price' => 5000]);

        // Первый заказ: 5000 − 2000 депозит = 3000, уходит в Точку как pending.
        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $block1->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $paymentA = Payment::where('tariff', 'block_1')->firstOrFail();
        $this->assertSame('pending', $paymentA->status);
        $this->assertEquals(3000, (float) $paymentA->amount);

        // Второй заказ на тот же курс, депозит ещё не потрачен (paymentA не оплачен) →
        // должен быть отклонён, а не создан со своим полным вычетом того же депозита.
        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $block2->id])
            ->assertSessionHasErrors('tariff_id');

        $this->assertSame(0, Payment::where('tariff', 'block_2')->count());

        // Первый заказ оплачен → депозит потрачен. Теперь второй заказ создаётся
        // нормально, но уже по полной цене (депозита больше нет).
        $paymentA->update(['status' => 'paid']);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $block2->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $paymentB = Payment::where('tariff', 'block_2')->firstOrFail();
        $this->assertEquals(5000, (float) $paymentB->amount);
    }
}
