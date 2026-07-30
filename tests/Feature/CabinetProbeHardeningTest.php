<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CabinetProbeRun;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H1794 hardening: public URLs, student smoke, history row.
 */
class CabinetProbeHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '');
        config()->set('cabinet_probe.telegram_soft_chat_id', '');
        config()->set('cabinet_probe.check_server_guards', false); // host OS audit is prod-only (H1914)
        config()->set('server_guards.verify_enabled', false);
        config()->set('services.telegram.bot_token', '');
        config()->set('features.cabinet_hybrid', false);
        config()->set('cabinet_probe.public_surfaces', [
            ['uri' => '/login', 'label' => 'public /login', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.student_surfaces', [
            ['name' => 'student.dashboard', 'label' => 'student /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.hybrid_surfaces', []);
        Cache::flush();
    }

    public function test_ensure_test_student_creates_null_role_user(): void
    {
        config([
            'services.test_student.email' => 'stu@example.com',
            'services.test_student.password' => 'student-pass-123',
            'services.test_student.name' => 'Smoke Student',
        ]);

        $this->artisan('users:ensure-test-student')->assertSuccessful();

        $user = User::query()->where('email', 'stu@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->role);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertTrue(Hash::check('student-pass-123', $user->password));
    }

    public function test_probe_records_history_and_passes_manager_student_public(): void
    {
        User::factory()->create([
            'email' => 'mgr@example.com',
            'password' => Hash::make('mgr-pass'),
            'role' => Roles::MANAGER,
        ]);
        User::factory()->create([
            'email' => 'stu@example.com',
            'password' => Hash::make('stu-pass'),
            'role' => null,
        ]);
        config([
            'services.test_manager.email' => 'mgr@example.com',
            'services.test_manager.password' => 'mgr-pass',
            'services.test_student.email' => 'stu@example.com',
            'services.test_student.password' => 'stu-pass',
            'cabinet_probe.ping_url' => '',
        ]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);

        $this->assertSame(1, CabinetProbeRun::query()->count(), $out);
        $run = CabinetProbeRun::query()->first();
        $this->assertNotNull($run);
        $this->assertTrue(
            (bool) $run->healthy,
            'probe unhealthy: '.json_encode($run->failures, JSON_UNESCAPED_UNICODE).' | out='.$out
        );
        $this->assertFalse((bool) $run->critical);
    }

    public function test_probe_dry_skips_history(): void
    {
        User::factory()->create([
            'email' => 'mgr@example.com',
            'password' => Hash::make('mgr-pass'),
            'role' => Roles::MANAGER,
        ]);
        config([
            'services.test_manager.email' => 'mgr@example.com',
            'services.test_manager.password' => 'mgr-pass',
            'services.test_student.password' => '',
        ]);

        Artisan::call('cabinet:probe', ['--dry' => true]);
        $this->assertSame(0, CabinetProbeRun::query()->count());
    }
}
