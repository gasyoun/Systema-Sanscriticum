<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use App\Models\PranaTransaction;
use App\Models\User;
use App\Services\Prana\PranaSettings;
use App\Services\StreakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Серия активных дней (streak): +1 за новый день, сброс при пропуске, no-op в тот
 * же день, бонус праны на вехе.
 */
class StreakServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::flushCached();
        MarketingSetting::create(['is_prana_active' => true]);
        config(['prana.streak_bonuses' => [7 => 50, 30 => 200]]);
    }

    private function svc(): StreakService
    {
        return app(StreakService::class);
    }

    private function user(int $days, ?string $lastDay): User
    {
        return User::factory()->create([
            'streak_days' => $days,
            'streak_last_day' => $lastDay,
            'prana_balance' => 0,
            'lifetime_prana' => 0,
        ]);
    }

    /** @test */
    public function first_activity_starts_streak_at_one(): void
    {
        $u = $this->user(0, null);

        $this->assertSame(1, $this->svc()->touch($u));
        $this->assertSame(1, (int) $u->fresh()->streak_days);
        $this->assertSame(Carbon::today()->toDateString(), Carbon::parse($u->fresh()->streak_last_day)->toDateString());
    }

    /** @test */
    public function consecutive_day_increments(): void
    {
        $u = $this->user(3, Carbon::yesterday()->toDateString());

        $this->assertSame(4, $this->svc()->touch($u));
    }

    /** @test */
    public function gap_resets_streak_to_one(): void
    {
        $u = $this->user(5, Carbon::today()->subDays(3)->toDateString());

        $this->assertSame(1, $this->svc()->touch($u));
    }

    /** @test */
    public function same_day_is_noop(): void
    {
        $u = $this->user(4, Carbon::today()->toDateString());

        $this->assertSame(4, $this->svc()->touch($u));
        $this->assertSame(0, PranaTransaction::count()); // ничего не начислили
    }

    /** @test */
    public function reaching_milestone_awards_prana_bonus(): void
    {
        $this->assertTrue(PranaSettings::isActive());
        $u = $this->user(6, Carbon::yesterday()->toDateString()); // завтра → 7

        $this->assertSame(7, $this->svc()->touch($u));
        $this->assertSame(50, (int) $u->fresh()->prana_balance);
        $this->assertDatabaseHas('prana_transactions', [
            'user_id' => $u->id, 'reason' => 'streak', 'amount' => 50,
        ]);
    }

    /** @test */
    public function non_milestone_day_awards_nothing(): void
    {
        $u = $this->user(2, Carbon::yesterday()->toDateString()); // → 3, не веха

        $this->svc()->touch($u);
        $this->assertSame(0, (int) $u->fresh()->prana_balance);
    }

    /** @test */
    public function next_milestone_helper(): void
    {
        $this->assertSame(['next' => 7, 'remaining' => 4], StreakService::nextMilestone(3));
        $this->assertSame(['next' => 30, 'remaining' => 5], StreakService::nextMilestone(25));
        $this->assertNull(StreakService::nextMilestone(30));
    }
}
