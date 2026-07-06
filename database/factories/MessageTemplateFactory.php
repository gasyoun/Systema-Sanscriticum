<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->sentence(3),
            'body' => 'Намасте, {name}! Курс «{course}», блок №{block}. Оплата: {pay_link}',
            'category' => MessageTemplate::CATEGORY_REACTIVATION,
            'is_active' => true,
        ];
    }

    public function category(string $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
