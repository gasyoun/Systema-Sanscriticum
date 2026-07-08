<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Дымовой рендер «Check-in целей» (H376) + гейт по роли, тем же паттерном, что
 * ProfitFundsPageSmokeTest (H259).
 */
class GoalCheckinsPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeGoal(): Goal
    {
        $owner = User::factory()->create(['role' => Roles::ADMIN]);

        return Goal::create([
            'user_id' => $owner->id,
            'title' => 'MRR к концу месяца',
            'target_metric' => 'MRR',
            'starting_value' => 0,
            'target_value' => 500_000,
            'period_start' => Carbon::now()->startOfMonth(),
            'period_end' => Carbon::now()->endOfMonth(),
            'status' => Goal::STATUS_ACTIVE,
        ]);
    }

    public function test_renders_for_accountant(): void
    {
        $this->makeGoal();
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/goal-checkins')->assertSuccessful();
    }

    public function test_renders_with_no_active_goals(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)->get('/admin/goal-checkins')->assertSuccessful();
    }

    public function test_manager_cannot_access(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/goal-checkins')->assertForbidden();
    }

    public function test_record_checkins_command_runs_dry(): void
    {
        $this->makeGoal();

        $this->artisan('goals:record-checkins', ['--dry' => true])->assertSuccessful();
    }

    public function test_record_checkins_command_persists_history(): void
    {
        $goal = $this->makeGoal();

        $this->artisan('goals:record-checkins')->assertSuccessful();

        $this->assertSame(1, $goal->checkins()->count());
    }
}
