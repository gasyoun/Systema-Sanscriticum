<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DealStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealStage>
 */
class DealStageFactory extends Factory
{
    protected $model = DealStage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => 'Стадия '.fake()->unique()->words(2, true),
            'position' => fake()->numberBetween(1, 99),
            'is_won' => false,
            'is_lost' => false,
        ];
    }
}
