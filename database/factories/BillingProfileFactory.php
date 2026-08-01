<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingConsolidatedCadence;
use App\Enums\BillingPreferredMode;
use App\Models\BillingProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingProfile>
 */
class BillingProfileFactory extends Factory
{
    protected $model = BillingProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'preferred_mode' => BillingPreferredMode::PerCourse,
            'consolidated_cadence' => null,
            'tochka_consumer_id' => null,
            'default_charge_day' => null,
        ];
    }

    public function consolidated(): static
    {
        return $this->state(fn () => [
            'preferred_mode' => BillingPreferredMode::Consolidated,
            'consolidated_cadence' => BillingConsolidatedCadence::Monthly1,
            'default_charge_day' => 1,
        ]);
    }
}
