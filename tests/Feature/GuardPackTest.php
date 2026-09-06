<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use App\Support\ServerGuards\SystemInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeSystemInspector;
use Tests\TestCase;

/**
 * H4194 guard pack: RAM/swap/load + queue-lag + compromise-integrity.
 * Synthetic fixtures per check, healthy-silent + --dry + alert-fires branches,
 * same shape as StorageUsageWatchdogTest (H1345).
 */
class GuardPackTest extends TestCase
{
    use RefreshDatabase;

    private string $adminBaselinePath;

    private string $webrootBaselinePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminBaselinePath = storage_path('app/guard-pack-test/admin_baseline.json');
        $this->webrootBaselinePath = storage_path('app/guard-pack-test/webroot_baseline.json');
        config([
            'guard_pack.admin_baseline_path' => $this->adminBaselinePath,
            'guard_pack.webroot_php_baseline_path' => $this->webrootBaselinePath,
            'guard_pack.webroot_scan_dir' => storage_path('app/guard-pack-test/webroot'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/guard-pack-test'));
        parent::tearDown();
    }

    private function fakeInspector(string $meminfo, string $loadavg, string $cpuinfo): FakeSystemInspector
    {
        $fake = new FakeSystemInspector;
        $fake->files['/proc/meminfo'] = $meminfo;
        $fake->files['/proc/loadavg'] = $loadavg;
        $fake->files['/proc/cpuinfo'] = $cpuinfo;
        $this->app->instance(SystemInspector::class, $fake);

        return $fake;
    }

    private function cpuinfoFor(int $cores): string
    {
        return implode("\n", array_fill(0, $cores, 'processor       : 0'))."\n";
    }

    // --- guards:resources ---------------------------------------------------

    /** @test */
    public function healthy_resources_are_a_silent_success(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        $this->fakeInspector(
            "MemTotal:       16000000 kB\nMemAvailable:   14000000 kB\nSwapTotal:       4000000 kB\nSwapFree:        4000000 kB\n",
            "0.20 0.30 0.40 1/200 12345\n",
            $this->cpuinfoFor(8),
        );

        $this->artisan('guards:resources')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function low_available_memory_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        // 10% avail — below the 15% default threshold (L1 shape).
        $this->fakeInspector(
            "MemTotal:       16000000 kB\nMemAvailable:    1600000 kB\nSwapTotal:       4000000 kB\nSwapFree:        4000000 kB\n",
            "0.20 0.30 0.40 1/200 12345\n",
            $this->cpuinfoFor(8),
        );

        $this->artisan('guards:resources')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function high_load1_per_core_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        // load1=370 on 8 cores — the L8 incident shape.
        $this->fakeInspector(
            "MemTotal:       16000000 kB\nMemAvailable:   14000000 kB\nSwapTotal:       4000000 kB\nSwapFree:        4000000 kB\n",
            "370.0 200.0 100.0 5/400 99999\n",
            $this->cpuinfoFor(8),
        );

        $this->artisan('guards:resources')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function excess_swap_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        // swap used 3.5GB of 16GB RAM ~= 22%... use higher for clarity: 5GB/16GB = 31% > 25%.
        $this->fakeInspector(
            "MemTotal:       16000000 kB\nMemAvailable:   14000000 kB\nSwapTotal:        5000000 kB\nSwapFree:               0 kB\n",
            "0.20 0.30 0.40 1/200 12345\n",
            $this->cpuinfoFor(8),
        );

        $this->artisan('guards:resources')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function dry_run_never_notifies(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        $this->fakeInspector(
            "MemTotal:       16000000 kB\nMemAvailable:    1600000 kB\nSwapTotal:       4000000 kB\nSwapFree:        4000000 kB\n",
            "0.20 0.30 0.40 1/200 12345\n",
            $this->cpuinfoFor(8),
        );

        $this->artisan('guards:resources --dry')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function missing_proc_fails_open_silently(): void
    {
        $fake = new FakeSystemInspector; // no /proc/meminfo entry
        $this->app->instance(SystemInspector::class, $fake);

        $this->artisan('guards:resources')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    // --- guards:queue-lag -----------------------------------------------------

    /** @test */
    public function empty_queue_is_a_silent_success(): void
    {
        $this->artisan('guards:queue-lag')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function stalled_queue_alerts_admins(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'synthetic']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - (45 * 60), // 45 min old, over the 30 min threshold
            'created_at' => time() - (45 * 60),
        ]);

        $this->artisan('guards:queue-lag')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function fresh_queue_job_is_healthy(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'synthetic']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->artisan('guards:queue-lag')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function queue_dry_run_never_notifies(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'synthetic']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - (45 * 60),
            'created_at' => time() - (45 * 60),
        ]);

        $this->artisan('guards:queue-lag --dry')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    // --- guards:compromise-integrity -----------------------------------------

    /** @test */
    public function first_run_writes_baselines_without_alerting(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        File::ensureDirectoryExists(storage_path('app/guard-pack-test/webroot'));
        File::put(storage_path('app/guard-pack-test/webroot/index.php'), '<?php');

        $this->artisan('guards:compromise-integrity')->assertSuccessful();

        $this->assertTrue(File::exists($this->adminBaselinePath));
        $this->assertTrue(File::exists($this->webrootBaselinePath));
        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function admin_count_growth_beyond_baseline_alerts(): void
    {
        File::ensureDirectoryExists(dirname($this->adminBaselinePath));
        File::put($this->adminBaselinePath, json_encode(['admin_count' => 1]));
        File::ensureDirectoryExists(storage_path('app/guard-pack-test/webroot'));

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        User::factory()->create(['role' => Roles::SUPER_ADMIN]); // 2 admins now vs baseline 1

        $this->artisan('guards:compromise-integrity')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function new_webroot_php_file_beyond_baseline_alerts(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        File::ensureDirectoryExists(dirname($this->adminBaselinePath));
        File::put($this->adminBaselinePath, json_encode(['admin_count' => 1]));

        $webroot = storage_path('app/guard-pack-test/webroot');
        File::ensureDirectoryExists($webroot);
        File::put($webroot.'/index.php', '<?php');
        File::put($this->webrootBaselinePath, json_encode(['files' => ['index.php']]));

        // Dropper lands after baseline was taken.
        File::put($webroot.'/galex_patch.php', '<?php /* dropper */');

        $this->artisan('guards:compromise-integrity')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function compromise_dry_run_never_writes_baseline_or_notifies(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        File::ensureDirectoryExists(storage_path('app/guard-pack-test/webroot'));

        $this->artisan('guards:compromise-integrity --dry')->assertSuccessful();

        $this->assertFalse(File::exists($this->adminBaselinePath));
        $this->assertFalse(File::exists($this->webrootBaselinePath));
        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function write_baseline_flag_accepts_current_state_as_new_norm(): void
    {
        File::ensureDirectoryExists(dirname($this->adminBaselinePath));
        File::put($this->adminBaselinePath, json_encode(['admin_count' => 1]));
        File::ensureDirectoryExists(storage_path('app/guard-pack-test/webroot'));
        File::put($this->webrootBaselinePath, json_encode(['files' => []]));

        User::factory()->create(['role' => Roles::ADMIN]);
        User::factory()->create(['role' => Roles::SUPER_ADMIN]);

        $this->artisan('guards:compromise-integrity --write-baseline')->assertSuccessful();

        $baseline = json_decode((string) File::get($this->adminBaselinePath), true);
        $this->assertSame(2, $baseline['admin_count']);
        $this->assertDatabaseCount('notifications', 0);
    }
}
