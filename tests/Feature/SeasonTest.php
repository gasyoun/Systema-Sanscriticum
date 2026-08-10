<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Season;
use App\Models\SeasonLeaderboardCache;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    use RefreshDatabase;

    /** lifetime_prana не fillable — массовое присваивание его теряет. */
    private function setLifetimePrana(User $user, int $value): void
    {
        DB::table('users')
            ->where('id', $user->id)
            ->update(['lifetime_prana' => $value]);

        $user->refresh();
        $this->assertSame($value, (int) $user->lifetime_prana);
    }

    /** @test */
    public function season_open_command_creates_season_and_initializes_leaderboard(): void
    {
        $user = User::factory()->create();
        $this->setLifetimePrana($user, 500);

        $this->artisan('season:open')
            ->assertExitCode(0);

        $season = Season::where('is_active', true)->first();
        $this->assertNotNull($season);
        $this->assertEquals('autumn-2026', $season->slug);
        $this->assertTrue($season->is_active);

        $cache = SeasonLeaderboardCache::where('season_id', $season->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($cache);
        $this->assertEquals(500, $cache->baseline_lifetime_prana);
        $this->assertEquals(0, $cache->prana_earned);
    }

    /** @test */
    public function season_refresh_leaderboard_updates_prana_earned(): void
    {
        // lifetime_prana НЕ в User::$fillable (растёт только через PranaService),
        // поэтому массовое присваивание его молча выбросит — пишем напрямую.
        $user = User::factory()->create();
        $this->setLifetimePrana($user, 500);

        $season = Season::factory()->create(['is_active' => true]);

        SeasonLeaderboardCache::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'baseline_lifetime_prana' => 500,
            'prana_earned' => 0,
            'rank_position' => 0,
            'computed_at' => now(),
        ]);

        // Студент заработал ещё 300 праны за сезон.
        $this->setLifetimePrana($user, 800);

        $this->artisan('season:refresh-leaderboard', ['season_id' => $season->id])
            ->assertExitCode(0);

        $cache = SeasonLeaderboardCache::where('season_id', $season->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertEquals(300, $cache->prana_earned);
        $this->assertEquals(1, $cache->rank_position);
    }

    /** @test */
    public function season_close_command_awards_rewards_and_deactivates_season(): void
    {
        $user = User::factory()->create(['prana_balance' => 500]);
        $this->setLifetimePrana($user, 1000);
        // adminAdjust требует админа для аудита — роль, а не legacy-флаг is_admin.
        User::factory()->create(['role' => Roles::SUPER_ADMIN]);
        $season = Season::factory()->create([
            'is_active' => true,
            'rewards_config' => [
                ['position' => 1, 'type' => 'prana', 'amount' => 5000],
            ],
        ]);

        SeasonLeaderboardCache::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'baseline_lifetime_prana' => 500,
            'prana_earned' => 500,
            'rank_position' => 1,
            'computed_at' => now(),
        ]);

        $this->artisan('season:close', ['season_id' => $season->id])
            ->assertExitCode(0);

        $season->refresh();
        $this->assertFalse($season->is_active);
        $this->assertNotNull($season->ended_at);

        $this->assertDatabaseHas('season_rewards', [
            'season_id' => $season->id,
            'user_id' => $user->id,
            'position' => 1,
            'reward_type' => 'prana',
            'reward_value' => 5000,
        ]);
    }

    /** @test */
    public function season_is_active_returns_correct_status(): void
    {
        $this->assertFalse(Season::isActive());

        Season::factory()->create(['is_active' => true]);

        $this->assertTrue(Season::isActive());
    }

    /** @test */
    public function season_refresh_leaderboard_is_registered_in_schedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $found = false;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'season:refresh-leaderboard')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'season:refresh-leaderboard не найден в расписании Kernel');
    }

    /** @test */
    public function season_open_is_registered_in_schedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $found = false;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'season:open 1')) {
                $found = true;
                // Verify cron expression: 01.09.2026 00:00 MSK (UTC 21:00 31.08)
                $this->assertSame('0 21 31 8 *', $event->expression);
                break;
            }
        }

        $this->assertTrue($found, 'season:open 1 не найден в расписании Kernel');
    }

    /** @test */
    public function season_close_is_registered_in_schedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $found = false;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'season:close 1')) {
                $found = true;
                // Verify cron expression: 01.01.2027 00:00 MSK (UTC 21:00 31.12.2026)
                $this->assertSame('0 21 31 12 *', $event->expression);
                break;
            }
        }

        $this->assertTrue($found, 'season:close 1 не найден в расписании Kernel');
    }
}
