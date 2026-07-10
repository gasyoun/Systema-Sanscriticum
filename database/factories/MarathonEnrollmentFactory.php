<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarathonEnrollment>
 */
class MarathonEnrollmentFactory extends Factory
{
    protected $model = MarathonEnrollment::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'track' => MarathonEnrollment::TRACK_FREE,
            'cohort' => MarathonEnrollment::COHORT_ZERO,
            'quiz_goal' => 'grammar',
            'day0_started_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['track' => MarathonEnrollment::TRACK_PAID]);
    }

    /** H445 Phase 1 */
    public function deva(): static
    {
        return $this->state(fn () => ['cohort' => MarathonEnrollment::COHORT_DEVA]);
    }
}
