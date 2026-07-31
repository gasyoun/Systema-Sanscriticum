<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BillingAmountPolicy;
use App\Enums\BillingCommitmentKind;
use App\Enums\BillingCommitmentStatus;
use App\Enums\BillingConsolidatedCadence;
use App\Enums\BillingPreferredMode;
use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingCommitment;
use App\Models\BillingProfile;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2026 Phase 0 — models cast enums; scaffold persists without live Tochka calls.
 * Executor: Grok 4.5 (grok-4.5).
 */
class BillingRecurringModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_profile_casts_preferred_mode_and_cadence(): void
    {
        $user = User::factory()->create();

        $profile = BillingProfile::factory()->create([
            'user_id' => $user->id,
            'preferred_mode' => BillingPreferredMode::Consolidated,
            'consolidated_cadence' => BillingConsolidatedCadence::Monthly2,
            'default_charge_day' => 15,
        ]);

        $fresh = $profile->fresh();

        $this->assertInstanceOf(BillingPreferredMode::class, $fresh->preferred_mode);
        $this->assertSame(BillingPreferredMode::Consolidated, $fresh->preferred_mode);
        $this->assertSame(BillingConsolidatedCadence::Monthly2, $fresh->consolidated_cadence);
        $this->assertSame(15, $fresh->default_charge_day);
        $this->assertTrue($fresh->user->is($user));
    }

    public function test_billing_subscription_casts_mode_status_policy_and_assigns_uuid(): void
    {
        $user = User::factory()->create();

        $sub = BillingSubscription::factory()->create([
            'user_id' => $user->id,
            'mode' => BillingSubscriptionMode::PerCourse,
            'status' => BillingSubscriptionStatus::Active,
            'amount_policy' => BillingAmountPolicy::Fixed,
            'amount_rub' => '3500.50',
            'period' => 'Month',
            'tranche_count' => 6,
        ]);

        $fresh = $sub->fresh();

        $this->assertNotEmpty($fresh->uuid);
        $this->assertSame(BillingSubscriptionMode::PerCourse, $fresh->mode);
        $this->assertSame(BillingSubscriptionStatus::Active, $fresh->status);
        $this->assertSame(BillingAmountPolicy::Fixed, $fresh->amount_policy);
        $this->assertSame('3500.50', (string) $fresh->amount_rub);
        $this->assertSame('tochka', $fresh->provider);
    }

    public function test_billing_commitment_casts_kind_status_and_links_subscription(): void
    {
        $user = User::factory()->create();
        $sub = BillingSubscription::factory()->create(['user_id' => $user->id]);

        $commitment = BillingCommitment::factory()->create([
            'billing_subscription_id' => $sub->id,
            'user_id' => $user->id,
            'kind' => BillingCommitmentKind::InstallmentRow,
            'status' => BillingCommitmentStatus::Scheduled,
            'amount_rub' => 1000,
            'due_on' => '2026-08-15',
        ]);

        $fresh = $commitment->fresh();

        $this->assertSame(BillingCommitmentKind::InstallmentRow, $fresh->kind);
        $this->assertSame(BillingCommitmentStatus::Scheduled, $fresh->status);
        $this->assertTrue($fresh->subscription->is($sub));
        $this->assertSame('2026-08-15', $fresh->due_on->toDateString());
    }

    public function test_subscription_has_many_commitments(): void
    {
        $user = User::factory()->create();
        $sub = BillingSubscription::factory()->create(['user_id' => $user->id]);

        BillingCommitment::factory()->count(2)->create([
            'billing_subscription_id' => $sub->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(2, $sub->fresh()->commitments);
    }
}
