<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Console\Commands\ActivateLadderV2Tariffs;
use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\LadderV2;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Лестница подписей с 01-12-2026 (рулинг 27-11-2026). */
class LadderV2Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_activates_on_december_first_and_override_wins(): void
    {
        config(['membership.ladder_v2.override' => null, 'membership.ladder_v2.start_date' => '2026-12-01']);

        Carbon::setTestNow('2026-11-30 23:59'); // МСК по умолчанию в тестах
        $this->assertFalse(app(LadderV2::class)->active());
        Carbon::setTestNow('2026-12-01 00:01');
        $this->assertTrue(app(LadderV2::class)->active());

        config(['membership.ladder_v2.override' => false]);
        $this->assertFalse(app(LadderV2::class)->active());
        config(['membership.ladder_v2.override' => true]);
        Carbon::setTestNow('2026-11-30 12:00');
        $this->assertTrue(app(LadderV2::class)->active());
    }

    public function test_labels_are_new_names_with_internal_keys_unchanged(): void
    {
        $ladder = app(LadderV2::class);
        $this->assertSame('Базовый', $ladder->labelFor(MembershipTier::Free));
        $this->assertSame('Оптимальный', $ladder->labelFor(MembershipTier::Basic));
        $this->assertSame('Максимальный', $ladder->labelFor(MembershipTier::Club));
        $this->assertSame('Премиум', $ladder->labelFor(MembershipTier::Top));
        $this->assertSame('basic', MembershipTier::Basic->value);
    }

    public function test_basic_capabilities_work_after_launch_without_old_advanced_flag(): void
    {
        config([
            'features.club_membership' => true,
            'features.membership_advanced_features' => false,
            'membership.ladder_v2.override' => true,
        ]);
        $user = User::factory()->create();

        $freeUser = User::factory()->create();
        ClubMembership::query()->create([
            'user_id' => $user->id,
            'term_months' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'grace_until' => now()->addDays(4),
            'grace_days' => 3,
            'source' => 'manual',
        ]);
        $entitlement = app(ClubEntitlement::class);

        $this->assertTrue($entitlement->allows($user, 'library'));
        $this->assertTrue($entitlement->allows($user, 'standard_exercises'));
        $this->assertFalse($entitlement->allows($freeUser, 'library'));
        $this->assertTrue($entitlement->allows($user, 'recordings')); // старый гейт не сломан

        config(['membership.ladder_v2.override' => false]);
        $this->assertFalse($entitlement->allows($user, 'library'));
    }

    public function test_tariff_activation_command_is_dry_run_by_default_and_idempotent(): void
    {
        config(['membership.ladder_v2.override' => true]);
        $tariff = Tariff::factory()->create([
            'membership_tier' => MembershipTier::Basic->value,
            'membership_months' => 1,
            'is_active' => false,
        ]);

        $this->artisan(ActivateLadderV2Tariffs::class)->assertSuccessful();
        $this->assertFalse($tariff->fresh()->is_active);

        $this->artisan(ActivateLadderV2Tariffs::class, ['--apply' => true])->assertSuccessful();
        $this->assertTrue($tariff->fresh()->is_active);

        $this->artisan(ActivateLadderV2Tariffs::class, ['--apply' => true])->assertSuccessful();
        $this->assertTrue($tariff->fresh()->is_active);
    }
}
