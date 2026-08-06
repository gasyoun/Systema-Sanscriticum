<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
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
 * Регрессия (money-core, H071 #13): при свежем pending с тем же промокодом
 * createPayment молча обнулял промокод и создавал новый заказ по ПОЛНОЙ цене —
 * пользователь уходил в банк на сумму больше показанной на чекауте. Теперь это
 * явная ошибка валидации, а не тихое повышение цены.
 */
class PromoRecentPendingTest extends TestCase
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
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_1'],
            ], 200),
        ]);
    }

    /** @test */
    public function fresh_pending_with_same_promo_errors_instead_of_charging_full_price(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $tariff = Tariff::factory()->for($course)->create(['price' => 10000]);
        $promo = PromoCode::create(['code' => 'SALE20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);

        // Первый чекаут: создаётся pending со скидкой (8000).
        $this->actingAs($user)
            ->withSession(['promo_code' => $promo->code])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        $first = Payment::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame(8000.0, (float) $first->amount);
        $this->assertSame($promo->id, $first->promo_code_id);

        // Второй чекаут в течение окна с тем же кодом → ошибка, НЕ новый заказ.
        $this->actingAs($user)
            ->withSession(['promo_code' => $promo->code])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertSessionHasErrors('promo_code');

        // Второй платёж не создан — по-прежнему ровно один.
        $this->assertSame(1, Payment::where('user_id', $user->id)->count());
    }
}
