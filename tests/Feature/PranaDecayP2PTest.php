<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PranaDecayP2PTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Щедрые лимиты по умолчанию; тесты на лимиты переопределяют сами.
        config(['prana.daily_p2p_limit' => 100000, 'prana.daily_p2p_per_user_limit' => 100000]);
    }

    private function withBalance(int $balance, int $lifetime = 0): User
    {
        $user = User::factory()->create();
        $user->forceFill(['prana_balance' => $balance, 'lifetime_prana' => $lifetime])->save();

        return $user;
    }

    /** @test */
    public function transfer_moves_balance_without_touching_lifetime(): void
    {
        $from = $this->withBalance(50, lifetime: 50);
        $to = $this->withBalance(0, lifetime: 0);

        app(PranaService::class)->transfer($from, $to, 20);

        $from = $from->fresh();
        $to = $to->fresh();
        $this->assertSame(30, (int) $from->prana_balance);
        $this->assertSame(50, (int) $from->lifetime_prana, 'Перевод не понижает ранг отправителя.');
        $this->assertSame(20, (int) $to->prana_balance);
        $this->assertSame(0, (int) $to->lifetime_prana, 'Полученное в подарок не растит ранг получателя.');
    }

    /** @test */
    public function transfer_rejects_self_and_insufficient_balance(): void
    {
        $u = $this->withBalance(10);
        $other = $this->withBalance(0);

        $this->expectExceptionMessageMatches('/самому себе/');
        app(PranaService::class)->transfer($u, $u, 5);
    }

    /** @test */
    public function transfer_rejects_when_balance_too_low(): void
    {
        $from = $this->withBalance(5);
        $to = $this->withBalance(0);

        $this->expectException(\RuntimeException::class);
        app(PranaService::class)->transfer($from, $to, 10);
    }

    /** @test */
    public function transfer_enforces_daily_total_limit(): void
    {
        config(['prana.daily_p2p_limit' => 30, 'prana.daily_p2p_per_user_limit' => 100]);
        $from = $this->withBalance(1000);
        $a = $this->withBalance(0);
        $b = $this->withBalance(0);

        $service = app(PranaService::class);
        $service->transfer($from, $a, 25); // 25 за день
        $this->expectExceptionMessageMatches('/лимит перевода/u');
        $service->transfer($from, $b, 10); // 25+10 > 30
    }

    /** @test */
    public function transfer_enforces_per_recipient_limit(): void
    {
        config(['prana.daily_p2p_limit' => 1000, 'prana.daily_p2p_per_user_limit' => 10]);
        $from = $this->withBalance(1000);
        $to = $this->withBalance(0);

        $service = app(PranaService::class);
        $service->transfer($from, $to, 8);
        $this->expectExceptionMessageMatches('/не более 10/u');
        $service->transfer($from, $to, 5); // 8+5 > 10 одному
    }

    /** @test */
    public function decay_is_skipped_when_disabled(): void
    {
        config(['prana.decay.enabled' => false]);
        $u = $this->withBalance(100);
        $u->forceFill(['last_login_at' => now()->subDays(60), 'last_activity_at' => now()->subDays(60)])->save();

        $this->assertSame(0, app(PranaService::class)->decayInactive());
        $this->assertSame(100, (int) $u->fresh()->prana_balance);
    }

    /** @test */
    public function decay_burns_balance_of_inactive_users_only(): void
    {
        config(['prana.decay.enabled' => true, 'prana.decay.inactive_days' => 30, 'prana.decay.percent' => 10]);

        $inactive = $this->withBalance(100, lifetime: 100);
        $inactive->forceFill(['last_login_at' => now()->subDays(90), 'last_activity_at' => now()->subDays(60)])->save();

        $active = $this->withBalance(100);
        $active->forceFill(['last_login_at' => now()->subDays(90), 'last_activity_at' => now()->subHours(2)])->save();

        $affected = app(PranaService::class)->decayInactive();

        $this->assertSame(1, $affected);
        $this->assertSame(90, (int) $inactive->fresh()->prana_balance);   // сожгли 10%
        $this->assertSame(100, (int) $inactive->fresh()->lifetime_prana); // ранг не тронут
        $this->assertSame(100, (int) $active->fresh()->prana_balance);    // активного не трогаем
    }

    /** @test */
    public function decay_command_runs(): void
    {
        config(['prana.decay.enabled' => false]);
        $this->artisan('prana:decay')->assertSuccessful();
    }
}
