<?php

namespace Database\Factories;

use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'title'        => $this->faker->sentence(4),
            'topic'        => $this->faker->sentence(),
            'lesson_date'  => now()->subDays($this->faker->numberBetween(0, 30)),
            'block_number' => 1,
            'is_published' => true,
            'is_free'      => false,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['is_free' => true]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
