<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(3);

        return [
            'title'      => $title,
            'slug'       => Str::slug($title) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'is_visible' => true,
            'is_active'  => true,
            'format'     => 'recorded',
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['format' => 'live']);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_visible' => false]);
    }
}
