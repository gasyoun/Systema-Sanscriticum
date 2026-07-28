<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1773 — csrf:mismatch-digest replaces the manual nginx-log read that was
 * the only way anyone found out about the 27-07-2026 419 incident. Covers
 * both sides of the threshold boundary (silent below, alerts above) by the
 * same pattern as {@see \Tests\Feature\StorageUsageWatchdogTest}.
 *
 * Seeds log lines by writing the channel's JSON format directly to disk
 * rather than through Log::channel('csrf_mismatch')->warning(...) (already
 * exercised end-to-end by {@see \Tests\Feature\CsrfMismatchTelemetryTest}):
 * Monolog's RotatingFileHandler keeps the file open for the life of the
 * LogManager instance, and on Windows an open handle from one test can leave
 * the next test's unlink()+reopen of the same dated path denied. Writing the
 * fixture bytes directly decouples the digest's read-side test from that
 * write-side handle lifecycle.
 */
class CsrfMismatchDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeCsrfMismatchLogs();
    }

    protected function tearDown(): void
    {
        $this->purgeCsrfMismatchLogs();
        parent::tearDown();
    }

    private function purgeCsrfMismatchLogs(): void
    {
        foreach (glob(storage_path('logs/csrf-mismatch-*.log')) ?: [] as $file) {
            @unlink($file);
        }
    }

    private function seedMismatches(int $count, string $route = 'login.post', ?string $datetime = null): void
    {
        $file = storage_path('logs/csrf-mismatch-'.now()->format('Y-m-d').'.log');
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = json_encode([
                'message' => 'CSRF token mismatch',
                'context' => [
                    'route' => $route,
                    'method' => 'POST',
                    'path' => 'login',
                    'authenticated' => false,
                    'expects_json' => false,
                    'client_bucket' => 'browser',
                ],
                'level' => 300,
                'level_name' => 'WARNING',
                'channel' => 'csrf_mismatch',
                'datetime' => $datetime ?? now()->toIso8601String(),
                'extra' => [],
            ]);
        }

        file_put_contents($file, implode("\n", $lines)."\n", FILE_APPEND);
    }

    /** @test */
    public function digest_is_silent_below_threshold(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        config(['csrf.digest_threshold' => 20]);
        $this->seedMismatches(19);

        $this->artisan('csrf:mismatch-digest')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function digest_alerts_admins_above_threshold(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        config(['csrf.digest_threshold' => 20]);
        $this->seedMismatches(20);

        $this->artisan('csrf:mismatch-digest')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    /** @test */
    public function digest_reports_a_per_route_breakdown(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        config(['csrf.digest_threshold' => 5]);
        $this->seedMismatches(3, 'login.post');
        $this->seedMismatches(4, 'shop.login');

        $this->artisan('csrf:mismatch-digest')
            ->expectsOutputToContain('login.post: 3')
            ->expectsOutputToContain('shop.login: 4')
            ->assertSuccessful();
    }

    /** @test */
    public function dry_run_reports_but_never_notifies(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        config(['csrf.digest_threshold' => 5]);
        $this->seedMismatches(10);

        $this->artisan('csrf:mismatch-digest --dry')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function breach_without_any_admin_fails_loudly(): void
    {
        config(['csrf.digest_threshold' => 5]);
        $this->seedMismatches(10);

        $this->artisan('csrf:mismatch-digest')->assertFailed();
    }

    /** @test */
    public function entries_outside_the_window_are_not_counted(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        config(['csrf.digest_threshold' => 1]);

        // Timestamped 30 days back — the digest's 1-day window must exclude them.
        $this->seedMismatches(5, 'login.post', now()->subDays(30)->toIso8601String());

        $this->artisan('csrf:mismatch-digest --days=1')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @test */
    public function csrf_mismatch_digest_is_scheduled_daily(): void
    {
        $event = $this->eventFor('csrf:mismatch-digest');

        $this->assertNotNull($event, 'csrf:mismatch-digest должен быть в расписании.');
        // dailyAt('04:25') → «25 4 * * *».
        $this->assertSame('25 4 * * *', $event->expression);
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
