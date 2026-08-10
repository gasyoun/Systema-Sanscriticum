<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        return [
            'title' => 'Test Season',
            'slug' => $this->faker->unique()->slug,
            'started_at' => now(),
            'ended_at' => null,
            'is_active' => false,
            'enabled_packs' => ['verb-roots', 'ligatures'],
            'rewards_config' => [],
        ];
    }
}
