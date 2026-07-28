<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Пульс кабинета (H1777): login smoke-менеджера + ключевые поверхности.
 *
 * Как SchedulerHeartbeatTest: fail-open, /fail при поломке, no-op без env.
 */
class CabinetProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PING = 'https://hc-ping.com/cabinet-probe-test-uuid';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabinet_probe.ping_url', self::PING);
        config()->set('cabinet_probe.timeout', 5);
        config()->set('cabinet_probe.telegram_chat_id', ''); // TG off unless a test enables it
        config()->set('services.telegram.bot_token', '');
        config()->set('features.cabinet_hybrid', false);
        Cache::forget('cabinet_probe:was_down');
        Cache::forget('cabinet_probe:last_tg_alert_at');

        // Always-on student surfaces (no hybrid 404s).
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'cabinet /dvaram'],
            ['name' => 'student.messages', 'label' => 'cabinet /messages'],
            ['name' => 'student.calendar', 'label' => 'cabinet /calendar'],
            ['name' => 'student.open-lessons', 'label' => 'cabinet /open-lessons'],
        ]);
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.library', 'label' => 'cabinet /library (hybrid)'],
        ]);
    }

    private function seedManager(string $password = 'probe-secret-123'): User
    {
        $user = User::factory()->create([
            'email' => 'smoke@example.com',
            'password' => Hash::make($password),
            'role' => Roles::MANAGER,
            'name' => 'Smoke Manager',
        ]);

        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => $password,
        ]);

        return $user;
    }

    private function fakeHealthchecks(): void
    {
        Http::fake([
            self::PING => Http::response('OK', 200),
            self::PING.'/fail' => Http::response('OK', 200),
        ]);
    }

    public function test_no_op_when_password_missing(): void
    {
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => '',
        ]);
        Http::fake();

        $this->artisan('cabinet:probe')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_healthy_login_and_surfaces_ping_success_url(): void
    {
        $this->seedManager();
        $this->fakeHealthchecks();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);

        Http::assertSent(fn ($request) => $request->url() === self::PING);
    }

    public function test_wrong_password_pings_fail_endpoint(): void
    {
        $this->seedManager('correct-pass');
        config(['services.test_manager.password' => 'wrong-pass']);
        $this->fakeHealthchecks();

        Artisan::call('cabinet:probe');

        Http::assertSent(fn ($request) => $request->url() === self::PING.'/fail');
    }

    public function test_missing_user_pings_fail_endpoint(): void
    {
        config([
            'services.test_manager.email' => 'nobody@example.com',
            'services.test_manager.password' => 'any-pass-here',
        ]);
        $this->fakeHealthchecks();

        Artisan::call('cabinet:probe');

        Http::assertSent(fn ($request) => $request->url() === self::PING.'/fail');
    }

    public function test_dry_run_skips_healthchecks_ping(): void
    {
        $this->seedManager();
        Http::fake();

        $this->artisan('cabinet:probe', ['--dry' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_no_ping_url_still_runs_checks_without_http(): void
    {
        $this->seedManager();
        config()->set('cabinet_probe.ping_url', '');
        Http::fake();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);

        Http::assertNothingSent();
    }

    public function test_healthchecks_network_error_is_fail_open(): void
    {
        $this->seedManager();
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $this->artisan('cabinet:probe')->assertSuccessful();
    }

    public function test_registered_in_the_schedule_with_config_cron(): void
    {
        config()->set('cabinet_probe.cron', '*/12 * * * *');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'cabinet:probe'));

        $this->assertCount(1, $events, 'cabinet:probe не зарегистрирован в расписании');
        $this->assertSame('*/12 * * * *', $events->first()?->expression);
        $this->assertTrue(
            $events->first()?->evenInMaintenanceMode,
            'cabinet:probe должен бить и в maintenance'
        );
    }

    public function test_manager_can_reach_filament_admin_surface(): void
    {
        $this->seedManager();
        config()->set('cabinet_probe.surfaces', [
            ['uri' => '/admin', 'label' => 'filament /admin', 'panel' => true],
        ]);
        config()->set('cabinet_probe.ping_url', '');

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
    }

    public function test_hybrid_surfaces_skipped_when_flag_off(): void
    {
        $this->seedManager();
        config()->set('features.cabinet_hybrid', false);
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'cabinet /dvaram'],
        ]);
        // Would 404 if wrongly probed while hybrid OFF.
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.library', 'label' => 'cabinet /library (hybrid)'],
        ]);
        config()->set('cabinet_probe.ping_url', '');

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
        $this->assertStringNotContainsString('library', $out);
    }

    public function test_failure_sends_telegram_and_respects_cooldown(): void
    {
        $this->seedManager('correct-pass');
        config(['services.test_manager.password' => 'wrong-pass']);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('cabinet_probe.telegram_cooldown_minutes', 60);
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        Artisan::call('cabinet:probe');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && ($r['chat_id'] ?? null) == '999001'
            && str_contains((string) ($r['text'] ?? ''), 'Личный кабинет не работает'));

        // Second fail within cooldown — no extra send.
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertNothingSent();

        // --force-alert bypasses cooldown.
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe', ['--force-alert' => true]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_recovery_sends_telegram_once(): void
    {
        $this->seedManager();
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('services.telegram.bot_token', 'test-bot-token');
        Cache::put('cabinet_probe:was_down', true, now()->addHour());

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $code = Artisan::call('cabinet:probe');
        $this->assertSame(0, $code);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'снова работает'));
    }
}
