<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rq4Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1005 -- RQ4 admin stats page. Lets a human check enrollment numbers via
 * the web admin panel without SSH to the production server.
 */
class Rq4StudyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/rq4-study-dashboard')->assertSuccessful();
    }

    public function test_dashboard_denied_for_non_admin(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_admin' => false]);

        $this->actingAs($student)->get('/admin/rq4-study-dashboard')->assertForbidden();
    }

    public function test_stats_reflect_real_enrollment_and_phase_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        Rq4Participant::create([
            'user_id' => User::factory()->create()->id,
            'prior_exposure' => 'kochergina',
            'arm' => 'A',
            'consented_at' => now(),
            'pre_test_completed_at' => now(),
        ]);
        Rq4Participant::create([
            'user_id' => User::factory()->create()->id,
            'prior_exposure' => 'kochergina',
            'arm' => 'B',
            'consented_at' => now(),
        ]);

        $res = $this->actingAs($admin)->get('/admin/rq4-study-dashboard')->assertSuccessful();
        $res->assertSee('Группа A (on-ramp): 1', false);
        $res->assertSee('Группа B (Талмуд): 1', false);
    }
}
