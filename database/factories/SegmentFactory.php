<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    protected $model = Segment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Сегмент '.fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'criteria' => [],
            'is_builtin' => false,
            'builtin_key' => null,
            'created_by' => null,
        ];
    }
}
