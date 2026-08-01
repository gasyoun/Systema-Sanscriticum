<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingAmountPolicy;
use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingSubscription>
 */
class BillingSubscriptionFactory extends Factory
{
    protected $model = BillingSubscription::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'mode' => BillingSubscriptionMode::PerCourse,
            'provider' => 'tochka',
            'provider_subscription_id' => null,
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'period' => 'Month',
            'tranche_count' => 12,
            'amount_rub' => 5000.00,
            'amount_policy' => BillingAmountPolicy::Fixed,
            'anchor_date' => null,
            'course_id' => null,
            'installment_group_id' => null,
            'club_product_id' => null,
            'feature_flag_snapshot' => [
                'tochka_recurring' => false,
            ],
            'meta' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => BillingSubscriptionStatus::Active,
            'anchor_date' => now()->toDateString(),
            'provider_subscription_id' => 'op_test_'.Str::random(8),
        ]);
    }

    public function installment(): static
    {
        return $this->state(fn () => [
            'mode' => BillingSubscriptionMode::Installment,
            'amount_policy' => BillingAmountPolicy::InstallmentDue,
            'amount_rub' => null,
            'installment_group_id' => (string) Str::uuid(),
        ]);
    }
}
