<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportAccount;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckTelegramSupportSessionHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function no_enabled_accounts_is_a_silent_noop(): void
    {
        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function healthy_account_alerts_nobody(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-main',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subMinutes(3),
        ]);

        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function stale_sync_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-stale',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subHours(2),
        ]);

        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function sync_error_alerts_admins_even_within_lag_window(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-flaky',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subMinutes(1),
            'last_sync_error' => 'FLOOD_WAIT',
        ]);

        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function disabled_account_never_alerts(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-off',
            'is_enabled' => false,
            'last_successful_sync_at' => null,
            'last_sync_error' => 'some old error',
        ]);

        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function dry_run_never_sends_notifications(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-stale',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subHours(2),
        ]);

        $this->artisan('telegram-support:healthcheck --dry')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function respects_the_configurable_stale_threshold(): void
    {
        config(['services.telegram_support.stale_after_minutes' => 60]);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        TelegramSupportAccount::create([
            'name' => 'support-recent',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subMinutes(30),
        ]);

        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $admin->id]);
    }
}
