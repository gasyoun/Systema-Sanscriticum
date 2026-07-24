<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1582 — Phase 4 readiness command (never flips the flag).
 */
class HybridPhase4ReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function readiness_holds_when_baseline_window_short(): void
    {
        // Clock starts "yesterday" → < 14 days.
        $this->artisan('cabinet:hybrid-readiness', [
            '--baseline-start' => now()->subDay()->toDateString(),
            '--min-days' => 14,
        ])->assertFailed();

        $this->assertFalse(config('features.cabinet_hybrid'));
    }

    /** @test */
    public function readiness_mechanical_go_when_window_and_code_ok(): void
    {
        config(['features.cabinet_hybrid' => false]);

        $user = User::factory()->create();
        ActivityEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => ActivityEvent::CABINET_HOME_VIEW,
            'event_data' => ['mode' => 'normal'],
            'created_at' => now()->subDays(3),
        ]);

        $this->artisan('cabinet:hybrid-readiness', [
            '--baseline-start' => now()->subDays(20)->toDateString(),
            '--min-days' => 14,
        ])->assertSuccessful();
    }

    /** @test */
    public function flag_stays_off_by_default(): void
    {
        $this->assertFalse((bool) config('features.cabinet_hybrid'));
    }
}
