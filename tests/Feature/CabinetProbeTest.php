<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use App\Support\ServerGuards\CabinetProbeAlertState;
use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\SystemInspector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSystemInspector;
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
        config()->set('cabinet_probe.telegram_soft_chat_id', '');
        config()->set('cabinet_probe.check_server_guards', false); // host OS audit is prod-only (H1914)
        config()->set('server_guards.verify_enabled', false);
        config()->set('services.telegram.bot_token', '');
        config()->set('services.test_student.password', ''); // H1794 student branch off unless a test enables it
        config()->set('features.cabinet_hybrid', false);
        Cache::forget('cabinet_probe:was_down');
        Cache::forget('cabinet_probe:last_tg_alert_at');
        Cache::forget('cabinet_probe:last_soft_tg_alert_at');
        Cache::forget('cabinet_probe:last_soft_fingerprint');
        $statePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cabinet_probe_tg_'.getmypid().'.json';
        @unlink($statePath);
        config()->set('cabinet_probe.tg_state_path', $statePath);

        // Legacy H1777 tests isolate manager surfaces; public/student covered in CabinetProbeHardeningTest.
        config()->set('cabinet_probe.public_surfaces', []);
        config()->set('cabinet_probe.student_surfaces', []);
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
        // H1794: without manager password the auth branch is skipped; public/student
        // lists are empty in this suite — no healthchecks HTTP either when ping cleared.
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => '',
            'services.test_student.password' => '',
            'cabinet_probe.public_surfaces' => [],
            'cabinet_probe.ping_url' => '',
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

    /**
     * 28-08-2026 Tochka TLS incident: ANY HTTP answer from the acquiring API
     * (unauthorized 401/403/404 included) means the outbound TLS handshake
     * and HTTP path are alive — only connection-level failures are critical.
     */
    public function test_payment_tls_ok_on_any_http_status(): void
    {
        $this->seedManager();
        config([
            'cabinet_probe.check_payment_tls' => true,
            'cabinet_probe.payment_probe_url' => 'https://acquiring.example/api/payments',
        ]);
        Http::fake([
            self::PING => Http::response('OK', 200),
            self::PING.'/fail' => Http::response('OK', 200),
            'https://acquiring.example/*' => Http::response(['errorMessage' => 'Unauthorized'], 401),
        ]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
        $this->assertStringNotContainsString('payments:', $out);
        Http::assertSent(fn ($request) => $request->url() === self::PING);
    }

    /**
     * Connection-level failure to the acquiring API (the cURL error 60 class
     * that killed checkout for four days) must be a critical HTTP-class
     * finding: probe verdict sick, Better Stack /fail pinged.
     */
    public function test_payment_tls_connection_failure_is_critical(): void
    {
        $this->seedManager();
        config([
            'cabinet_probe.check_payment_tls' => true,
            'cabinet_probe.payment_probe_url' => 'https://acquiring.example/api/payments',
        ]);
        Http::fake([
            self::PING => Http::response('OK', 200),
            self::PING.'/fail' => Http::response('OK', 200),
            'https://acquiring.example/*' => fn () => throw new \RuntimeException('cURL error 60: SSL certificate problem'),
        ]);

        Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertStringContainsString('Кабинет болен', $out);
        $this->assertStringContainsString('[critical] payments: нет связи с acquiring.example', $out);
        Http::assertSent(fn ($request) => $request->url() === self::PING.'/fail');
    }

    /**
     * 02-09-2026 TLS flap false-positive: a single transient connection drop
     * to the acquiring API must NOT page (Better Stack /fail) — the probe
     * retries before declaring critical, and a retry success counts as alive.
     */
    public function test_payment_tls_transient_failure_retried_then_alive(): void
    {
        $this->seedManager();
        config([
            'cabinet_probe.check_payment_tls' => true,
            'cabinet_probe.payment_probe_url' => 'https://acquiring.example/api/payments',
            'cabinet_probe.payment_tls_pause_seconds' => 0,
        ]);
        $acquiringCalls = 0;
        Http::fake([
            self::PING => Http::response('OK', 200),
            self::PING.'/fail' => Http::response('OK', 200),
            'https://acquiring.example/*' => function () use (&$acquiringCalls) {
                if (++$acquiringCalls === 1) {
                    throw new \RuntimeException('cURL error 35: TLS connect error: unexpected eof while reading');
                }

                return Http::response(['errorMessage' => 'Unauthorized'], 401);
            },
        ]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
        $this->assertStringNotContainsString('payments:', $out);
        $this->assertSame(2, $acquiringCalls);
        Http::assertSent(fn ($request) => $request->url() === self::PING);
        Http::assertNotSent(fn ($request) => $request->url() === self::PING.'/fail');
    }

    /**
     * A persistent outage (the 4-day CA incident class) still alerts on the
     * same run after all retry attempts are exhausted.
     */
    public function test_payment_tls_persistent_failure_alerts_after_all_attempts(): void
    {
        $this->seedManager();
        config([
            'cabinet_probe.check_payment_tls' => true,
            'cabinet_probe.payment_probe_url' => 'https://acquiring.example/api/payments',
            'cabinet_probe.payment_tls_pause_seconds' => 0,
        ]);
        $acquiringCalls = 0;
        Http::fake([
            self::PING => Http::response('OK', 200),
            self::PING.'/fail' => Http::response('OK', 200),
            'https://acquiring.example/*' => function () use (&$acquiringCalls) {
                $acquiringCalls++;

                throw new \RuntimeException('cURL error 60: SSL certificate problem');
            },
        ]);

        Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertStringContainsString('Кабинет болен', $out);
        $this->assertStringContainsString('[critical] payments: нет связи с acquiring.example', $out);
        $this->assertStringContainsString('(3 попыток)', $out);
        $this->assertSame(3, $acquiringCalls);
        Http::assertSent(fn ($request) => $request->url() === self::PING.'/fail');
    }

    /**
     * H1931 item 3: SystemInspector is container-bound so a fake proves
     * cabinet:probe really wires guards:verify into the health verdict.
     * Before the bind, `new ShellSystemInspector` inside the command made this
     * untestable without a live Linux prod host.
     */
    public function test_critical_guard_finding_reaches_probe_verdict_via_di(): void
    {
        config([
            'services.test_manager.password' => '',
            'services.test_student.password' => '',
            'cabinet_probe.public_surfaces' => [],
            'cabinet_probe.ping_url' => '',
            'cabinet_probe.check_server_guards' => true,
            'server_guards.verify_enabled' => true,
            'server_guards.spec_path' => base_path('scripts/server_guards.conf'),
            'server_guards.template_root' => base_path('scripts/server_guards'),
        ]);

        $spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));
        $fake = FakeSystemInspector::healthy(
            $spec,
            base_path('scripts/server_guards'),
            (string) file_get_contents(base_path('scripts/server_guards/manifest.psv')),
        );
        $fake->active['earlyoom'] = false;
        $this->app->instance(SystemInspector::class, $fake);

        $code = Artisan::call('cabinet:probe', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out); // probe is fail-open on exit code
        $this->assertStringContainsString('Кабинет болен', $out);
        $this->assertStringContainsString('guards/', $out);
        $this->assertStringContainsString('earlyoom', $out);
        $this->assertStringContainsString('[critical]', $out);
    }

    public function test_healthy_fake_inspector_marks_guards_ok_via_di(): void
    {
        config([
            'services.test_manager.password' => '',
            'services.test_student.password' => '',
            'cabinet_probe.public_surfaces' => [],
            'cabinet_probe.ping_url' => '',
            'cabinet_probe.check_server_guards' => true,
            'server_guards.verify_enabled' => true,
            'server_guards.spec_path' => base_path('scripts/server_guards.conf'),
            'server_guards.template_root' => base_path('scripts/server_guards'),
        ]);

        $spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));
        $fake = FakeSystemInspector::healthy(
            $spec,
            base_path('scripts/server_guards'),
            (string) file_get_contents(base_path('scripts/server_guards/manifest.psv')),
        );
        $this->app->instance(SystemInspector::class, $fake);

        $code = Artisan::call('cabinet:probe', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
        $this->assertStringContainsString('Предохранители ОС на месте', $out);
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

    public function test_not_registered_in_the_in_process_schedule(): void
    {
        // H1917 (30-07-2026): раньше cabinet:probe стоял *внутри* schedule:run
        // ($schedule->command(...) в Kernel.php) — доказано живым прогоном на
        // проде, что это не работает как сторож: слот выпадает целиком, когда
        // сам schedule:run завис. Сторож живёт ОТДЕЛЬНОЙ строкой cron
        // (systema-watchdog-run.sh) со своим локом, не зависящим от
        // schedule:run. Этот тест теперь фиксирует обратное: команда НЕ должна
        // возвращаться в Kernel-расписание.
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'cabinet:probe'));

        $this->assertCount(
            0,
            $events,
            'cabinet:probe не должен быть в Kernel-расписании — сторож живёт отдельной строкой cron (H1917)'
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
        app(CabinetProbeAlertState::class)->put([
            CabinetProbeAlertState::HTTP_DOWN => true,
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $code = Artisan::call('cabinet:probe');
        $this->assertSame(0, $code);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'снова работает'));
    }

    public function test_soft_failure_sends_scoped_telegram_and_respects_soft_sticky(): void
    {
        $this->seedManager();
        // Critical surfaces OK; one soft surface with no route → soft-only failure.
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('features.cabinet_hybrid', true);
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.route.that.does.not.exist', 'label' => 'hybrid /library', 'severity' => 'soft'],
        ]);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('cabinet_probe.telegram_soft_reminder_hours', 24);
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        Artisan::call('cabinet:probe');
        Http::assertSent(function ($r) {
            $text = (string) ($r['text'] ?? '');

            return str_contains($r->url(), 'sendMessage')
                && str_contains($text, 'soft-сбой')
                && str_contains($text, '(hybrid)')
                && str_contains($text, 'hybrid /library');
        });

        // Same soft class within reminder window → no re-send (H2335 sticky).
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertNothingSent();

        // Different soft fingerprint → alert again immediately.
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.another.missing.route', 'label' => 'guards/auto-deploy', 'severity' => 'soft'],
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), '(guards)')
            && str_contains((string) ($r['text'] ?? ''), 'guards/auto-deploy'));

        // Same new class again → sticky (no hourly spam).
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertNothingSent();

        // After reminder window elapses → one re-nudge.
        app(CabinetProbeAlertState::class)->put([
            CabinetProbeAlertState::LAST_SOFT_ALERT_AT => now()->subHours(25),
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));

        // --force-alert bypasses soft sticky.
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe', ['--force-alert' => true]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_soft_reminder_zero_means_once_until_green(): void
    {
        $this->seedManager();
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('features.cabinet_hybrid', true);
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.route.that.does.not.exist', 'label' => 'hybrid /library', 'severity' => 'soft'],
        ]);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('cabinet_probe.telegram_soft_reminder_hours', 0);
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));

        app(CabinetProbeAlertState::class)->put([
            CabinetProbeAlertState::LAST_SOFT_ALERT_AT => now()->subDays(3),
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertNothingSent();
    }

    public function test_cache_flush_does_not_re_send_http_sos(): void
    {
        $this->seedManager('correct-pass');
        config(['services.test_manager.password' => 'wrong-pass']);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('cabinet_probe.telegram_soft_reminder_hours', 24);
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Личный кабинет не работает'));

        Cache::flush();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        Artisan::call('cabinet:probe');
        Http::assertNothingSent();
    }

    public function test_host_guard_critical_is_ops_not_sos_and_does_not_fail_deploy(): void
    {
        config([
            'services.test_manager.password' => '',
            'services.test_student.password' => '',
            'cabinet_probe.public_surfaces' => [],
            'cabinet_probe.ping_url' => '',
            'cabinet_probe.check_server_guards' => true,
            'server_guards.verify_enabled' => true,
            'server_guards.spec_path' => base_path('scripts/server_guards.conf'),
            'server_guards.template_root' => base_path('scripts/server_guards'),
            'cabinet_probe.telegram_chat_id' => '999001',
            'services.telegram.bot_token' => 'test-bot-token',
        ]);

        $spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));
        $fake = FakeSystemInspector::healthy(
            $spec,
            base_path('scripts/server_guards'),
            (string) file_get_contents(base_path('scripts/server_guards/manifest.psv')),
        );
        $fake->active['earlyoom'] = false;
        $this->app->instance(SystemInspector::class, $fake);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->artisan('cabinet:probe', ['--fail-on-critical' => true])
            ->expectsOutputToContain('Кабинет болен')
            ->expectsOutputToContain('earlyoom')
            ->assertSuccessful();

        Http::assertSent(function ($r) {
            $text = (string) ($r['text'] ?? '');

            return str_contains($text, 'host/ops')
                && ! str_contains($text, 'Личный кабинет не работает');
        });

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        $this->artisan('cabinet:probe', ['--fail-on-critical' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_no_alert_skips_telegram(): void
    {
        $this->seedManager('correct-pass');
        config(['services.test_manager.password' => 'wrong-pass']);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
        $this->artisan('cabinet:probe', ['--no-alert' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_dry_does_not_post_soft_webhook(): void
    {
        $this->seedManager();
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('features.cabinet_hybrid', true);
        config()->set('cabinet_probe.hybrid_surfaces', [
            ['name' => 'student.route.that.does.not.exist', 'label' => 'hybrid /library', 'severity' => 'soft'],
        ]);
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '999001');
        config()->set('cabinet_probe.soft_webhook_url', 'https://example.test/soft-hook');
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
            'https://example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('cabinet:probe', ['--dry' => true])->assertSuccessful();
        Http::assertNothingSent();
    }
}
