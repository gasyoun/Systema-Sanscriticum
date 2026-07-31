<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingCommitmentKind;
use App\Enums\BillingCommitmentStatus;
use App\Models\BillingCommitment;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCommitment>
 */
class BillingCommitmentFactory extends Factory
{
    protected $model = BillingCommitment::class;

    public function definition(): array
    {
        return [
            'billing_subscription_id' => BillingSubscription::factory(),
            'user_id' => User::factory(),
            'kind' => BillingCommitmentKind::CourseMonth,
            'course_id' => null,
            'tariff_id' => null,
            'payment_promise_id' => null,
            'amount_rub' => 5000.00,
            'covers_from' => now()->startOfMonth()->toDateString(),
            'covers_to' => now()->endOfMonth()->toDateString(),
            'access_key' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => BillingCommitmentStatus::Scheduled,
            'due_on' => now()->addDays(7)->toDateString(),
            'payment_id' => null,
            'meta' => null,
        ];
    }

    public function charged(): static
    {
        return $this->state(fn () => [
            'status' => BillingCommitmentStatus::Charged,
        ]);
    }
}
