<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Season;
use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3297 — DB-driven decay-flip механизм (закрытие @DECIDE §9
 * PLAN_SYSTEMA_SEASON_LIVE_SERVICE_SEPT_2026, вариант B).
 *
 * Деньги: decay сжигает тратимый баланс → каждое переключение проверяем
 * на обоих краях сезона (open включает, close гасит) и вне окна сезона.
 */
class SeasonDecayFlipTest extends TestCase
{
    use RefreshDatabase;

    /** Кандидат decay: баланс > 0, давно не заходил, last_login_at не NULL. */
    private function inactiveUser(int $balance = 500): User
    {
        return User::factory()->create([
            'prana_balance' => $balance,
            'last_login_at' => now()->subDays(60),
            'last_activity_at' => now()->subDays(60),
        ]);
    }

    private function service(): PranaService
    {
        return app(PranaService::class);
    }

    /** @test */
    public function decay_is_disabled_by_default_without_season(): void
    {
        config(['prana.decay.enabled' => false]);
        $user = $this->inactiveUser();

        $this->assertSame(0, $this->service()->decayInactive());
        $this->assertFalse(PranaService::isDecayEnabled());
        $this->assertSame(500, (int) $user->refresh()->prana_balance);
    }

    /** @test */
    public function active_season_db_flag_enables_decay_even_with_env_off(): void
    {
        config(['prana.decay.enabled' => false]);
        $user = $this->inactiveUser();
        Season::factory()->create(['is_active' => true, 'decay_enabled' => true]);

        $this->assertTrue(PranaService::isDecayEnabled());
        $this->assertSame(1, $this->service()->decayInactive());
        $this->assertSame(450, (int) $user->refresh()->prana_balance);
    }

    /** @test */
    public function season_open_sets_flag_and_close_clears_it(): void
    {
        config(['prana.decay.enabled' => false]);

        $this->artisan('season:open')->assertExitCode(0);

        $season = Season::where('is_active', true)->first();
        $this->assertTrue((bool) $season->decay_enabled, 'season:open должен ставить decay_enabled=true');
        $this->assertTrue(PranaService::isDecayEnabled());

        $this->artisan('season:close', ['season_id' => $season->id])->assertExitCode(0);

        $season->refresh();
        $this->assertFalse($season->is_active);
        $this->assertFalse((bool) $season->decay_enabled, 'season:close должен гасить decay_enabled (R4-1)');
        $this->assertFalse(PranaService::isDecayEnabled(), 'после закрытия сезона decay обязан быть выключен');
    }

    /** @test */
    public function closed_season_leftover_flag_does_not_enable_decay(): void
    {
        config(['prana.decay.enabled' => false]);
        $user = $this->inactiveUser();
        // Забытый true у НЕактивного сезона — decay всё равно вне окна.
        Season::factory()->create(['is_active' => false, 'decay_enabled' => true]);

        $this->assertFalse(PranaService::isDecayEnabled());
        $this->assertSame(0, $this->service()->decayInactive());
        $this->assertSame(500, (int) $user->refresh()->prana_balance);
    }

    /** @test */
    public function env_flag_still_works_without_season(): void
    {
        config(['prana.decay.enabled' => true]);
        $user = $this->inactiveUser();

        $this->assertTrue(PranaService::isDecayEnabled());
        $this->assertSame(1, $this->service()->decayInactive());
        $this->assertSame(450, (int) $user->refresh()->prana_balance);
    }
}
