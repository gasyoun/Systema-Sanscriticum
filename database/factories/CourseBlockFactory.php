<?php

namespace Database\Factories;

use App\Models\CourseBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseBlock>
 */
class CourseBlockFactory extends Factory
{
    protected $model = CourseBlock::class;

    public function definition(): array
    {
        return [
            'number'     => $this->faker->numberBetween(1, 100),
            'title'      => null,
            'is_current' => false,
            'is_active'  => true,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }

    public function withDates(?\DateTimeInterface $starts, ?\DateTimeInterface $ends): static
    {
        return $this->state(fn () => [
            'starts_at' => $starts,
            'ends_at'   => $ends,
        ]);
    }
}
