<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

final class MembershipTierTest extends MembershipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_advanced_features', true);
    }

    public function test_capability_matrix_is_monotonic_and_top_is_capped_until_go_no_go(): void
    {
        $free = User::factory()->create();
        $basic = User::factory()->create();
        $club = User::factory()->create();
        $top = User::factory()->create();
        $this->payClub($basic, 1, MembershipTier::Basic);
        $this->payClub($club, 1, MembershipTier::Club);
        $this->payClub($top, 1, MembershipTier::Top);

        $entitlement = app(ClubEntitlement::class);
        $this->assertFalse($entitlement->allows($free, 'library'));
        $this->assertTrue($entitlement->allows($basic, 'library'));
        $this->assertFalse($entitlement->allows($basic, 'recordings'));
        $this->assertTrue($entitlement->allows($club, 'recordings'));
        $this->assertFalse($entitlement->allows($top, 'private_archives'));

        config()->set('features.membership_top', true);
        app()->forgetInstance(ClubEntitlement::class);
        $this->assertTrue(app(ClubEntitlement::class)->allows($top, 'private_archives'));
    }

    public function test_classification_is_dry_by_default_and_requires_exact_counts(): void
    {
        $tariff = Tariff::factory()->create([
            'course_id' => $this->clubCourse->id,
            'membership_months' => 1,
            'membership_tier' => null,
        ]);
        $membership = ClubMembership::create([
            'user_id' => User::factory()->create()->id,
            'term_months' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'grace_until' => now()->addMonth()->addDays(3),
            'grace_days' => 3,
            'source' => ClubMembership::SOURCE_MANUAL,
        ]);

        $this->artisan('membership:classify-tiers')->assertSuccessful();
        $this->assertNull($tariff->fresh()->membership_tier);
        $this->assertNull($membership->fresh()->tier_code);

        $this->artisan('membership:classify-tiers', [
            '--apply' => true,
            '--expected-memberships' => 999,
            '--expected-tariffs' => 999,
        ])->assertFailed();

        $this->artisan('membership:classify-tiers', [
            '--apply' => true,
            '--expected-memberships' => 1,
            '--expected-tariffs' => 1,
        ])->assertSuccessful();
        $this->assertSame(MembershipTier::Club, $tariff->fresh()->membership_tier);
        $this->assertSame(MembershipTier::Club, $membership->fresh()->tier_code);
    }
}
