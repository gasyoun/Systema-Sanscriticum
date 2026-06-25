<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PranaTwoCounterTest extends TestCase
{
    use RefreshDatabase;

    private function fresh(User $user): User
    {
        return $user->fresh();
    }

    /** @test */
    public function award_increases_both_balance_and_lifetime(): void
    {
        $user = User::factory()->create();

        app(PranaService::class)->award($user, 'lesson_complete'); // 10

        $user = $this->fresh($user);
        $this->assertSame(10, (int) $user->prana_balance);
        $this->assertSame(10, (int) $user->lifetime_prana);
    }

    /** @test */
    public function spending_reduces_balance_but_not_lifetime(): void
    {
        $user = User::factory()->create();
        $service = app(PranaService::class);

        $service->adminAdjust($user, 100, $user); // balance 100, lifetime 100
        $service->spend($user, 30, 'spent_on_purchase');

        $user = $this->fresh($user);
        $this->assertSame(70, (int) $user->prana_balance);
        $this->assertSame(100, (int) $user->lifetime_prana, 'Траты не понижают накопительный ранг-счётчик.');
    }

    /** @test */
    public function admin_deduct_does_not_lower_lifetime(): void
    {
        $user = User::factory()->create();
        $service = app(PranaService::class);

        $service->adminAdjust($user, 100, $user); // lifetime 100
        $service->adminAdjust($user, -40, $user);  // снятие админом

        $user = $this->fresh($user);
        $this->assertSame(60, (int) $user->prana_balance);
        $this->assertSame(100, (int) $user->lifetime_prana);
    }

    /** @test */
    public function rank_thresholds_map_lifetime_to_rank_and_progress(): void
    {
        $this->assertSame('sishya', PranaSettings::rankFor(0)['key']);
        $this->assertSame('adhyayin', PranaSettings::rankFor(200)['key']);

        $mid = PranaSettings::rankFor(600); // между 200 и 1000
        $this->assertSame('adhyayin', $mid['key']);
        $this->assertSame(50, $mid['progress']);

        $top = PranaSettings::rankFor(9000);
        $this->assertSame('pandita', $top['key']);
        $this->assertNull($top['next_name']);
        $this->assertSame(100, $top['progress']);
    }

    /** @test */
    public function user_prana_rank_accessor_reflects_lifetime(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['lifetime_prana' => 1200])->save();

        $rank = $this->fresh($user)->pranaRank();
        $this->assertSame('snataka', $rank['key']);
        $this->assertSame('Ācārya · наставник', $rank['next_name']);
    }
}
