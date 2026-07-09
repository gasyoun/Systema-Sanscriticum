<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Goal;
use App\Models\User;
use App\Services\GoalCheckinService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Темп/светофор GoalCheckinService (H376): линейная интерполяция между
 * period_start/period_end к target_value, 15%-й допуск перед danger.
 */
class GoalCheckinServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeGoal(float $start, float $target): Goal
    {
        $owner = User::factory()->create(['role' => Roles::ADMIN]);

        return Goal::create([
            'user_id' => $owner->id,
            'title' => 'Тестовая цель',
            'target_metric' => 'MRR',
            'starting_value' => $start,
            'target_value' => $target,
            'period_start' => Carbon::parse('2026-01-01'),
            'period_end' => Carbon::parse('2026-01-31'),
            'status' => Goal::STATUS_ACTIVE,
        ]);
    }

    public function test_on_pace_is_success(): void
    {
        $goal = $this->makeGoal(0, 100);
        $service = app(GoalCheckinService::class);
        $asOf = Carbon::parse('2026-01-16'); // ~half the period

        $service->recordCheckin($goal, 50.0, null, $asOf);

        $snap = $service->snapshot($asOf);
        $card = $snap['goals']->firstWhere('goal.id', $goal->id);

        $this->assertSame('success', $card['level']);
    }

    public function test_slightly_behind_is_warning(): void
    {
        $goal = $this->makeGoal(0, 100);
        $service = app(GoalCheckinService::class);
        $asOf = Carbon::parse('2026-01-16');

        // Expected ~50; 40 is 10 points behind on a 100-point span = 10% -> warning.
        $service->recordCheckin($goal, 40.0, null, $asOf);

        $snap = $service->snapshot($asOf);
        $card = $snap['goals']->firstWhere('goal.id', $goal->id);

        $this->assertSame('warning', $card['level']);
    }

    public function test_far_behind_is_danger(): void
    {
        $goal = $this->makeGoal(0, 100);
        $service = app(GoalCheckinService::class);
        $asOf = Carbon::parse('2026-01-16');

        $service->recordCheckin($goal, 10.0, null, $asOf);

        $snap = $service->snapshot($asOf);
        $card = $snap['goals']->firstWhere('goal.id', $goal->id);

        $this->assertSame('danger', $card['level']);
        $this->assertFalse($snap['ok']);
    }

    public function test_latest_value_falls_back_to_starting_value_without_checkins(): void
    {
        $goal = $this->makeGoal(25, 100);
        $service = app(GoalCheckinService::class);

        $this->assertSame(25.0, $service->latestValue($goal));
    }

    public function test_record_checkins_for_all_active_persists_history(): void
    {
        $goal = $this->makeGoal(0, 100);
        $service = app(GoalCheckinService::class);

        $service->recordCheckinsForAllActive(Carbon::parse('2026-01-10'));

        $this->assertSame(1, $goal->checkins()->count());
    }
}
