<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\ClubMembershipService;

/**
 * Жизненный цикл клубного периода (H2644): выдача по оплате и три инварианта
 * снятия — не раньше срока, не дважды, не по группам курсов.
 */
class ClubMembershipLifecycleTest extends MembershipTestCase
{
    private function service(): ClubMembershipService
    {
        return app(ClubMembershipService::class);
    }

    public function test_paid_club_payment_creates_period_and_puts_user_in_club_group(): void
    {
        $user = User::factory()->create();

        $payment = $this->payClub($user, 3);

        $membership = ClubMembership::where('payment_id', $payment->id)->first();
        $this->assertNotNull($membership, 'период членства не заведён по оплате');
        $this->assertSame(3, $membership->term_months);
        $this->assertSame(MembershipTier::Club, $membership->tier_code);
        $this->assertSame('membership_club_3m', $payment->tariff, 'ключ доступа должен быть самоописывающим');
        $this->assertTrue($membership->isActive());
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'grantAccess() должен был выдать клубную группу'
        );
    }

    public function test_sync_is_idempotent_per_payment(): void
    {
        $user = User::factory()->create();
        $payment = $this->payClub($user, 1);

        $this->service()->syncFromPayment($payment);
        $this->service()->syncFromPayment($payment);

        $this->assertSame(1, ClubMembership::where('payment_id', $payment->id)->count());
    }

    public function test_renewal_stacks_onto_the_tail_instead_of_restarting(): void
    {
        $user = User::factory()->create();
        $first = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();

        $this->travel(20)->days();

        $second = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();

        $this->assertTrue(
            $second->starts_at->equalTo($first->ends_at),
            'продление обязано начинаться с конца текущего периода, а не «сейчас» — иначе студент дарит школе остаток месяца'
        );
        $this->assertTrue($second->ends_at->greaterThan($first->ends_at));
    }

    public function test_higher_tier_starts_now_without_shortening_the_paid_basic_period(): void
    {
        config()->set('features.membership_tiered', true);
        $user = User::factory()->create();
        $basic = ClubMembership::where('payment_id', $this->payClub($user, 3, MembershipTier::Basic)->id)->firstOrFail();
        $this->travel(10)->days();

        $club = ClubMembership::where('payment_id', $this->payClub($user, 1, MembershipTier::Club)->id)->firstOrFail();

        $this->assertTrue($club->starts_at->isSameDay(now()));
        $this->assertTrue($basic->fresh()->ends_at->greaterThan(now()));
        $this->assertSame(MembershipTier::Club, $this->service()->activeTierFor($user->fresh()));
    }

    public function test_terms_have_exact_zero_five_and_fifteen_percent_prices(): void
    {
        $this->assertSame(1000, MembershipTier::Basic->priceForTerm(1));
        $this->assertSame(2850, MembershipTier::Basic->priceForTerm(3));
        $this->assertSame(10200, MembershipTier::Basic->priceForTerm(12));
        $this->assertSame(2000, MembershipTier::Club->priceForTerm(1));
        $this->assertSame(5700, MembershipTier::Club->priceForTerm(3));
        $this->assertSame(20400, MembershipTier::Club->priceForTerm(12));
    }

    public function test_tiered_mode_refuses_an_untyped_membership_payment(): void
    {
        config()->set('features.membership_tiered', true);
        $user = User::factory()->create();
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->clubCourse->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->assertNull($this->service()->syncFromPayment($payment));
        $this->assertSame(0, ClubMembership::where('payment_id', $payment->id)->count());
    }

    public function test_lapse_job_never_revokes_before_the_grace_window_closes(): void
    {
        $user = User::factory()->create();
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();

        // Оплаченный срок кончился, но грейс ещё идёт.
        $membership->forceFill([
            'ends_at' => now()->subDay(),
            'grace_until' => now()->addDays(2),
        ])->save();

        $report = $this->service()->expireDue(true);

        $this->assertSame(0, $report['revoked']);
        $this->assertNull($membership->fresh()->revoked_at);
        $this->assertTrue($user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists());
    }

    public function test_lapse_job_revokes_after_grace_and_detaches_only_the_club_group(): void
    {
        $user = User::factory()->create();
        $ordinaryGroup = $this->enrollInOrdinaryCourse($user);
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();

        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $report = $this->service()->expireDue(true);

        $this->assertSame(1, $report['revoked']);
        $this->assertNotNull($membership->fresh()->revoked_at);
        $this->assertSame(ClubMembership::REVOKE_LAPSED, $membership->fresh()->revoke_reason);
        $this->assertFalse(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'клубная группа должна быть снята'
        );
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $ordinaryGroup->id)->exists(),
            'ГРУППА ОПЛАЧЕННОГО КУРСА НЕ ДОЛЖНА БЫТЬ ТРОНУТА — это инцидент целостности, а не баг'
        );
    }

    public function test_lapse_job_refuses_to_detach_a_group_shared_with_another_course(): void
    {
        // Клубная группа по ошибке привязана и к обычному курсу. Отцепив её,
        // мы отняли бы у человека оплаченный КУРС — поэтому не отцепляем вовсе.
        $otherCourse = Course::factory()->create();
        $otherCourse->groups()->attach($this->clubGroup->id);

        $user = User::factory()->create();
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $report = $this->service()->expireDue(true);

        $this->assertSame([$this->clubGroup->id], $report['refused_groups']);
        $this->assertSame(0, $report['detached'], 'ни одна группа не должна быть отцеплена');
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'общая группа остаётся у студента'
        );
        // Период при этом всё равно закрыт: право кончилось, даже если группу
        // снять нельзя — иначе строка висела бы «активной» вечно.
        $this->assertNotNull($membership->fresh()->revoked_at);
    }

    public function test_expired_period_does_not_revoke_access_when_a_later_period_is_live(): void
    {
        $user = User::factory()->create();
        $first = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $this->payClub($user, 1); // продление встык

        $first->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $report = $this->service()->expireDue(true);

        $this->assertSame(1, $report['superseded']);
        $this->assertSame(0, $report['detached']);
        $this->assertSame(ClubMembership::REVOKE_SUPERSEDED, $first->fresh()->revoke_reason);
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'продлившийся член не должен терять группу'
        );
        $this->assertTrue($this->service()->hasActive($user->fresh()));
    }

    public function test_lapse_job_is_idempotent_across_runs(): void
    {
        $user = User::factory()->create();
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $this->service()->expireDue(true);
        $second = $this->service()->expireDue(true);

        $this->assertSame(0, $second['checked'], 'снятый период не должен попадать в выборку повторно');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $report = $this->service()->expireDue(false);

        $this->assertSame(1, $report['revoked'], 'отчёт показывает, что БЫЛО БЫ снято');
        $this->assertNull($membership->fresh()->revoked_at);
        $this->assertTrue($user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists());
    }

    public function test_ordinary_course_payment_creates_no_membership(): void
    {
        $user = User::factory()->create();
        $this->enrollInOrdinaryCourse($user);

        $this->assertSame(0, ClubMembership::where('user_id', $user->id)->count());
    }

    public function test_command_reports_and_writes_nothing_without_apply(): void
    {
        $user = User::factory()->create();
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $this->artisan('membership:expire-club')->assertSuccessful();

        $this->assertNull($membership->fresh()->revoked_at);

        $this->artisan('membership:expire-club --apply')->assertSuccessful();

        $this->assertNotNull($membership->fresh()->revoked_at);
    }

    public function test_command_is_inert_while_the_flag_is_off(): void
    {
        config()->set('features.club_membership', false);

        $user = User::factory()->create();
        $membership = $this->service()->grantManualPeriod($user, 1);
        $user->groups()->syncWithoutDetaching([$this->clubGroup->id]);
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $this->artisan('membership:expire-club --apply')->assertSuccessful();

        $this->assertNull($membership->fresh()->revoked_at, 'при выключенном флаге команда не должна писать');
    }

    public function test_no_club_course_means_no_detachable_groups(): void
    {
        config()->set('membership.club.course_slug', 'nonexistent-slug');

        $groups = app(ClubMembershipService::class)->detachableClubGroupIds();

        $this->assertSame([], $groups['allowed']);
        $this->assertSame([], $groups['refused']);
    }

    public function test_manual_period_can_be_granted_without_a_payment(): void
    {
        $user = User::factory()->create();

        $membership = $this->service()->grantManualPeriod($user, 2);

        $this->assertNull($membership->payment_id);
        $this->assertSame(ClubMembership::SOURCE_MANUAL, $membership->source);
        $this->assertSame(2, $membership->term_months);
        $this->assertTrue($membership->isActive());
    }

    public function test_group_of_a_second_club_course_is_never_touched(): void
    {
        // Группа, вообще не относящаяся к клубу, у пользователя-клубника.
        $user = User::factory()->create();
        $strayGroup = Group::create(['name' => 'Чужая группа']);
        $user->groups()->syncWithoutDetaching([$strayGroup->id]);
        $membership = ClubMembership::where('payment_id', $this->payClub($user, 1)->id)->firstOrFail();
        $membership->forceFill([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ])->save();

        $this->service()->expireDue(true);

        $this->assertTrue($user->fresh()->groups()->where('groups.id', $strayGroup->id)->exists());
    }
}
