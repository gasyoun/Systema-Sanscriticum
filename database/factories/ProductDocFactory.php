<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductDoc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductDoc>
 */
class ProductDocFactory extends Factory
{
    protected $model = ProductDoc::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'audience' => 'ops',
            'route_name' => null,
            'url_path' => '/admin',
            'faq_fragment' => null,
            'source_path' => null,
            'quiz_audience' => null,
            'access_gate' => 'catalog',
            'sort_order' => 100,
            'is_active' => true,
            'is_seeded' => false,
        ];
    }
}
