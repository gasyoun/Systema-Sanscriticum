<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регрессия (money-core, H071 #14): состояние лояльности в AJAX-ответе чекаута
 * читало удалённую колонку loyalty_discount_percent (миграция 2026_03_27_182336)
 * → loyaltyPercent всегда 0, а isLoyal ставился при ЛЮБОМ paid-платеже (включая
 * депозит). Бейдж «Скидка для своих: −0%» показывался тому, у кого лояльности нет,
 * и не показывал реальный процент тому, у кого она есть.
 */
class CheckoutLoyaltyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        MarketingSetting::create([
            'is_loyalty_active' => true,
            'wholesale_small_threshold' => 2,
            'wholesale_small_discount' => 10,
            'wholesale_large_threshold' => 4,
            'wholesale_large_discount' => 20,
        ]);
    }

    private function tariff(): Tariff
    {
        return Tariff::factory()->for(Course::factory()->create())->create(['price' => 10000]);
    }

    private function state(User $user, Tariff $tariff): array
    {
        return $this->actingAs($user)
            ->postJson(route('checkout.promo.remove', $tariff))
            ->assertOk()
            ->json('state');
    }

    /** @test */
    public function deposit_only_user_is_not_marked_loyal(): void
    {
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id, 'course_id' => Course::factory()->create()->id,
            'amount' => 2000, 'tariff' => 'deposit', 'status' => 'paid',
        ]);

        $state = $this->state($user, $this->tariff());

        $this->assertFalse($state['isLoyal']);
        $this->assertSame(0, $state['loyaltyPercent']);
    }

    /** @test */
    public function genuine_wholesale_tier_user_gets_real_percent(): void
    {
        $user = User::factory()->create();
        // 2 оплаченных уникальных курса → small-порог (2) → 10%.
        foreach (range(1, 2) as $i) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => Course::factory()->create()->id,
                'amount' => 4800, 'tariff' => 'full', 'status' => 'paid',
            ]);
        }

        $state = $this->state($user, $this->tariff());

        $this->assertTrue($state['isLoyal']);
        $this->assertSame(10, $state['loyaltyPercent']);
    }

    /** @test */
    public function user_below_threshold_is_not_loyal(): void
    {
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id, 'course_id' => Course::factory()->create()->id,
            'amount' => 4800, 'tariff' => 'full', 'status' => 'paid',
        ]);

        $state = $this->state($user, $this->tariff());

        $this->assertFalse($state['isLoyal']);
        $this->assertSame(0, $state['loyaltyPercent']);
    }
}
