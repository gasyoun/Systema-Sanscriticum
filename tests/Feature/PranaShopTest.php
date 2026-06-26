<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PranaPerk;
use App\Models\PranaRedemption;
use App\Models\User;
use App\Services\PranaShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Магазин праны (spend-sink): покупка перка списывает прану, создаёт заявку,
 * уменьшает остаток; нехватка/недоступность отбиваются.
 */
class PranaShopTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $balance): User
    {
        $u = User::factory()->create();
        $u->forceFill(['prana_balance' => $balance])->save();

        return $u;
    }

    private function perk(array $attrs = []): PranaPerk
    {
        return PranaPerk::create(array_merge([
            'name' => 'Стикерпак', 'cost' => 300, 'is_active' => true,
        ], $attrs));
    }

    private function svc(): PranaShopService
    {
        return app(PranaShopService::class);
    }

    /** @test */
    public function redeem_spends_prana_creates_redemption_and_decrements_stock(): void
    {
        $user = $this->user(1000);
        $perk = $this->perk(['cost' => 300, 'stock' => 5]);

        $redemption = $this->svc()->redeem($user, $perk);

        $this->assertSame(700, (int) $user->fresh()->prana_balance);
        $this->assertSame(4, (int) $perk->fresh()->stock);
        $this->assertSame(PranaRedemption::STATUS_PENDING, $redemption->status);
        $this->assertSame('Стикерпак', $redemption->perk_name);
        $this->assertSame(300, $redemption->cost);
        $this->assertDatabaseHas('prana_transactions', [
            'user_id' => $user->id, 'reason' => 'spent_on_perk', 'amount' => -300,
        ]);
    }

    /** @test */
    public function unlimited_stock_perk_is_not_decremented(): void
    {
        $user = $this->user(1000);
        $perk = $this->perk(['cost' => 100, 'stock' => null]);

        $this->svc()->redeem($user, $perk);

        $this->assertNull($perk->fresh()->stock);
        $this->assertSame(900, (int) $user->fresh()->prana_balance);
    }

    /** @test */
    public function insufficient_prana_is_rejected_without_side_effects(): void
    {
        $user = $this->user(100);
        $perk = $this->perk(['cost' => 300, 'stock' => 5]);

        try {
            $this->svc()->redeem($user, $perk);
            $this->fail('ожидалось исключение нехватки праны');
        } catch (RuntimeException $e) {
            // ок
        }

        $this->assertSame(100, (int) $user->fresh()->prana_balance);
        $this->assertSame(5, (int) $perk->fresh()->stock);      // остаток не тронут
        $this->assertSame(0, PranaRedemption::count());          // заявки нет
    }

    /** @test */
    public function inactive_or_out_of_stock_perk_is_rejected(): void
    {
        $user = $this->user(1000);

        $this->expectException(RuntimeException::class);
        $this->svc()->redeem($user, $this->perk(['is_active' => false]));
    }

    /** @test */
    public function out_of_stock_perk_is_rejected(): void
    {
        $user = $this->user(1000);
        $perk = $this->perk(['cost' => 100, 'stock' => 0]);

        $this->expectException(RuntimeException::class);
        $this->svc()->redeem($user, $perk);
    }

    /** @test */
    public function redeem_route_flashes_success(): void
    {
        $user = $this->user(1000);
        $perk = $this->perk(['cost' => 100]);

        $this->actingAs($user)
            ->post(route('student.prana.redeem', $perk))
            ->assertRedirect()
            ->assertSessionHas('prana_status');

        $this->assertSame(900, (int) $user->fresh()->prana_balance);
    }
}
