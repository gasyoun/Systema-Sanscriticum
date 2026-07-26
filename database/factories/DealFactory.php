<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_id' => null,
            'user_id' => null,
            'course_id' => null,
            'amount' => fake()->numberBetween(1000, 50000),
            'currency' => 'RUB',
            'stage_id' => DealStage::first()?->id ?? DealStage::factory(),
            'assigned_to' => null,
            'closed_at' => null,
            'closed_reason' => null,
            'source_payment_id' => null,
        ];
    }

    /** Сделка на выигрышной стадии (закрытая). */
    public function won(): static
    {
        return $this->state(fn (): array => [
            'stage_id' => DealStage::won()?->id,
            'closed_at' => now(),
            'closed_reason' => Deal::REASON_WON,
        ]);
    }
}
