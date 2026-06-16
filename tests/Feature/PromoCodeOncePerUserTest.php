<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правило «один человек — один раз»: код израсходован для юзера, когда у него
 * есть ОПЛАЧЕННЫЙ платёж с этим promo_code_id. Брошенный/pending чекаут не сжигает.
 */
class PromoCodeOncePerUserTest extends TestCase
{
    use RefreshDatabase;

    private function tariff(float $price = 12000): Tariff
    {
        return Tariff::factory()->create([
            'course_id' => Course::factory()->create()->id,
            'price' => $price,
        ]);
    }

    private function promo(): PromoCode
    {
        return PromoCode::create([
            'code' => 'ONCE',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);
    }

    private function payment(User $user, PromoCode $promo, string $status): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'promo_code_id' => $promo->id,
            'amount' => 10800,
            'tariff' => 'full',
            'status' => $status,
        ]);
    }

    /** @test */
    public function redeemed_by_user_reflects_paid_payment_only(): void
    {
        $promo = $this->promo();
        $paidUser = User::factory()->create();
        $pendingUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->payment($paidUser, $promo, 'paid');
        $this->payment($pendingUser, $promo, 'pending');

        $this->assertTrue($promo->redeemedByUser($paidUser->id));
        $this->assertFalse($promo->redeemedByUser($pendingUser->id));
        $this->assertFalse($promo->redeemedByUser($otherUser->id));
        $this->assertFalse($promo->redeemedByUser(null)); // гость
    }

    /** @test */
    public function apply_promo_rejects_user_who_already_redeemed(): void
    {
        $tariff = $this->tariff();
        $promo = $this->promo();
        $user = User::factory()->create();
        $this->payment($user, $promo, 'paid');

        $this->actingAs($user)
            ->postJson("/checkout/{$tariff->id}/promo", ['code' => 'ONCE'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function url_promo_ignored_for_user_who_already_redeemed(): void
    {
        $tariff = $this->tariff();
        $promo = $this->promo();
        $user = User::factory()->create();
        $this->payment($user, $promo, 'paid');

        $response = $this->actingAs($user)->get("/checkout/{$tariff->id}?promo=ONCE");

        $response->assertOk();
        $response->assertSee('12000', false); // полная цена, скидки нет
        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function pending_payment_does_not_burn_the_code(): void
    {
        $tariff = $this->tariff();
        $promo = $this->promo();
        $user = User::factory()->create();
        $this->payment($user, $promo, 'pending'); // незавершённый чекаут

        $this->actingAs($user)
            ->postJson("/checkout/{$tariff->id}/promo", ['code' => 'ONCE'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('ONCE', session('promo_code'));
    }

    /** @test */
    public function fresh_user_can_apply_the_code(): void
    {
        $tariff = $this->tariff();
        $this->promo();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/checkout/{$tariff->id}/promo", ['code' => 'ONCE'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('ONCE', session('promo_code'));
    }
}
