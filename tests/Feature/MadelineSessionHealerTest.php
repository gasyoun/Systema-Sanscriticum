<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportAccount;
use App\Models\User;
use App\Services\Telegram\MadelineSessionHealer;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MadelineSessionHealerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(MadelineSessionHealer::COOLDOWN_CACHE_KEY);
    }

    public function test_error_looks_healable_for_ipc_message(): void
    {
        $healer = app(MadelineSessionHealer::class);

        $this->assertTrue($healer->errorLooksHealable(
            'Could not connect to MadelineProto, please enable proc_open…',
        ));
        $this->assertFalse($healer->errorLooksHealable('FLOOD_WAIT_30'));
        $this->assertFalse($healer->errorLooksHealable(null));
    }

    public function test_recover_clears_ipc_and_keeps_safe_php(): void
    {
        $session = storage_path('framework/testing/healer-session.madeline');
        File::deleteDirectory($session);
        File::ensureDirectoryExists($session);
        File::put($session.DIRECTORY_SEPARATOR.'safe.php', 'credentials');
        File::put($session.DIRECTORY_SEPARATOR.'ipc', '');
        File::put($session.DIRECTORY_SEPARATOR.'callback.ipc', '');
        File::put($session.DIRECTORY_SEPARATOR.'lock', '');

        config([
            'services.telegram_support.session' => $session,
            'services.telegram_support.enabled' => false,
        ]);

        // Hold then force-release path: put a lock so recover can release it.
        $lock = Cache::lock(MadelineSessionHealer::LOCK_NAME, 60);
        $lock->get();

        $result = app(MadelineSessionHealer::class)->recover(runSync: false);

        $this->assertTrue($result['lock_released']);
        $this->assertContains('ipc', $result['removed']);
        $this->assertContains('callback.ipc', $result['removed']);
        $this->assertContains('lock', $result['removed']);
        $this->assertFileExists($session.DIRECTORY_SEPARATOR.'safe.php');
        $this->assertFileDoesNotExist($session.DIRECTORY_SEPARATOR.'ipc');

        File::deleteDirectory($session);
    }

    public function test_recover_command_dry_and_force(): void
    {
        $this->artisan('telegram-support:recover', ['--dry' => true])
            ->assertSuccessful();

        $session = storage_path('framework/testing/healer-cmd.madeline');
        File::deleteDirectory($session);
        File::ensureDirectoryExists($session);
        File::put($session.DIRECTORY_SEPARATOR.'safe.php', 'x');
        File::put($session.DIRECTORY_SEPARATOR.'ipc', '');
        config(['services.telegram_support.session' => $session]);

        $this->artisan('telegram-support:recover', [
            '--force' => true,
            '--no-sync' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($session.DIRECTORY_SEPARATOR.'ipc');
        $this->assertFileExists($session.DIRECTORY_SEPARATOR.'safe.php');

        File::deleteDirectory($session);
    }

    public function test_cooldown_blocks_second_recover_without_force(): void
    {
        config(['services.telegram_support.auto_heal_cooldown_minutes' => 30]);
        $healer = app(MadelineSessionHealer::class);
        $healer->markCooldown();

        $this->assertTrue($healer->isInCooldown());

        $this->artisan('telegram-support:recover')
            ->expectsOutputToContain('cooldown')
            ->assertSuccessful();
    }

    public function test_healthcheck_auto_heal_when_stale_and_enabled(): void
    {
        config([
            'services.telegram_support.auto_heal' => true,
            'services.telegram_support.stale_after_minutes' => 15,
            'services.telegram_support.auto_heal_cooldown_minutes' => 30,
        ]);

        User::factory()->create(['role' => Roles::ADMIN]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subHours(3),
            'last_sync_error' => 'Could not connect to MadelineProto, please enable proc_open',
        ]);

        $session = storage_path('framework/testing/healer-hc.madeline');
        File::deleteDirectory($session);
        File::ensureDirectoryExists($session);
        File::put($session.DIRECTORY_SEPARATOR.'safe.php', 'x');
        File::put($session.DIRECTORY_SEPARATOR.'ipc', '');
        config(['services.telegram_support.session' => $session]);

        // recover will call sync which may no-op / fail without real API — that's ok;
        // we assert heal ran (ipc cleared) and cooldown set.
        $this->artisan('telegram-support:healthcheck')->assertSuccessful();

        $this->assertFileDoesNotExist($session.DIRECTORY_SEPARATOR.'ipc');
        $this->assertTrue(app(MadelineSessionHealer::class)->isInCooldown());

        File::deleteDirectory($session);
    }
}
