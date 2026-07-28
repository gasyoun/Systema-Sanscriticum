<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnsureTestManagerCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function no_op_when_password_missing(): void
    {
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => '',
        ]);

        $this->artisan('users:ensure-test-manager')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->where('email', 'smoke@example.com')->count());
    }

    /** @test */
    public function creates_manager_with_working_password(): void
    {
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => 'smoke-secret-123',
            'services.test_manager.name' => 'Smoke Manager',
        ]);

        $this->artisan('users:ensure-test-manager')
            ->assertSuccessful();

        $user = User::query()->where('email', 'smoke@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(Roles::MANAGER, $user->role);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertTrue(Hash::check('smoke-secret-123', $user->password));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    /** @test */
    public function is_idempotent_and_resyncs_password(): void
    {
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => 'first-pass-123',
            'services.test_manager.name' => 'Smoke Manager',
        ]);
        $this->artisan('users:ensure-test-manager')->assertSuccessful();

        config(['services.test_manager.password' => 'second-pass-456']);
        $this->artisan('users:ensure-test-manager')->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'smoke@example.com')->count());
        $user = User::query()->where('email', 'smoke@example.com')->first();
        $this->assertTrue(Hash::check('second-pass-456', $user->password));
        $this->assertSame(Roles::MANAGER, $user->role);
    }

    /** @test */
    public function refuses_to_overwrite_super_admin(): void
    {
        User::factory()->create([
            'email' => 'smoke@example.com',
            'role' => Roles::SUPER_ADMIN,
            'password' => Hash::make('keep-me'),
        ]);

        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => 'hijack-attempt',
        ]);

        $this->artisan('users:ensure-test-manager')
            ->assertFailed();

        $user = User::query()->where('email', 'smoke@example.com')->first();
        $this->assertSame(Roles::SUPER_ADMIN, $user->role);
        $this->assertTrue(Hash::check('keep-me', $user->password));
    }

    /** @test */
    public function refuses_to_overwrite_student_email(): void
    {
        User::factory()->create([
            'email' => 'student@example.com',
            'role' => null,
            'password' => Hash::make('student-pass'),
        ]);

        config([
            'services.test_manager.email' => 'student@example.com',
            'services.test_manager.password' => 'hijack-attempt',
        ]);

        $this->artisan('users:ensure-test-manager')
            ->assertFailed();

        $user = User::query()->where('email', 'student@example.com')->first();
        $this->assertNull($user->role);
        $this->assertTrue(Hash::check('student-pass', $user->password));
    }

    /** @test */
    public function manager_logs_in_via_web_login_route(): void
    {
        config([
            'services.test_manager.email' => 'smoke@example.com',
            'services.test_manager.password' => 'smoke-secret-123',
            'services.test_manager.name' => 'Smoke Manager',
        ]);
        $this->artisan('users:ensure-test-manager')->assertSuccessful();

        $response = $this->from('/login')->post('/login', [
            'email' => 'smoke@example.com',
            'password' => 'smoke-secret-123',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isManager());
        // is_admin=false → кабинет, не /admin (Filament — отдельный /admin/login).
        $response->assertRedirect(route('student.dashboard'));
    }
}
