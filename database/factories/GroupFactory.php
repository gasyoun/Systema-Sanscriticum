<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        $name = 'Группа '.$this->faker->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
