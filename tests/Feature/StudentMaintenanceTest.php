<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\StudentMaintenance;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StudentMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $attrs = []): MarketingSetting
    {
        return MarketingSetting::create(array_merge([
            'student_maintenance_enabled' => false,
        ], $attrs));
    }

    /** @test */
    public function disabled_lets_student_through(): void
    {
        $this->settings(['student_maintenance_enabled' => false]);
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)->get('/home')->assertStatus(302); // редирект, не 503
    }

    /** @test */
    public function enabled_shows_maintenance_to_student(): void
    {
        $this->settings(['student_maintenance_enabled' => true]);
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)->get('/home')
            ->assertStatus(503)
            ->assertSee('техобслуживании', false);
    }

    /** @test */
    public function enabled_lets_admin_through(): void
    {
        $this->settings(['student_maintenance_enabled' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/home')->assertStatus(302); // админ проходит
    }

    /** @test */
    public function custom_message_is_shown(): void
    {
        $this->settings([
            'student_maintenance_enabled' => true,
            'student_maintenance_message' => 'Вернёмся в 18:00 МСК',
        ]);
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)->get('/home')->assertSee('Вернёмся в 18:00 МСК', false);
    }

    /** @test */
    public function valid_secret_cookie_bypasses_via_middleware(): void
    {
        $settings = $this->settings([
            'student_maintenance_enabled' => true,
            'student_maintenance_secret' => 'SECRET123',
        ]);

        $request = Request::create('/dvaram', 'GET');
        $request->cookies->set('student_maintenance_bypass', 'SECRET123');
        $request->setUserResolver(fn () => null);

        $response = (new StudentMaintenance)->handle($request, fn () => response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    /** @test */
    public function wrong_secret_cookie_does_not_bypass(): void
    {
        $this->settings([
            'student_maintenance_enabled' => true,
            'student_maintenance_secret' => 'SECRET123',
        ]);

        $request = Request::create('/dvaram', 'GET');
        $request->cookies->set('student_maintenance_bypass', 'WRONG');
        $request->setUserResolver(fn () => null);

        $response = (new StudentMaintenance)->handle($request, fn () => response('OK'));

        $this->assertSame(503, $response->getStatusCode());
    }

    /** @test */
    public function bypass_link_sets_cookie_for_valid_secret(): void
    {
        $this->settings([
            'student_maintenance_enabled' => true,
            'student_maintenance_secret' => 'SECRET123',
        ]);
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)->get('/maintenance-bypass/SECRET123')
            ->assertRedirect(route('student.dashboard'))
            ->assertCookie('student_maintenance_bypass', 'SECRET123');
    }

    /** @test */
    public function bypass_link_404_for_wrong_secret(): void
    {
        $this->settings([
            'student_maintenance_enabled' => true,
            'student_maintenance_secret' => 'SECRET123',
        ]);
        $student = User::factory()->create(['is_admin' => false]);

        $this->actingAs($student)->get('/maintenance-bypass/NOPE')->assertNotFound();
    }
}
