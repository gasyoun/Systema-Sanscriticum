<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\ClubMembership;
use App\Models\User;

/**
 * Отказ от продления (H2644): гасит намерение, НЕ отбирает оплаченное.
 */
class MembershipCancellationTest extends MembershipTestCase
{
    private function clubMember(): User
    {
        $user = User::factory()->create();
        $this->payClub($user, 1);

        return $user->fresh();
    }

    public function test_cancel_records_the_intent_without_touching_access(): void
    {
        $user = $this->clubMember();

        $this->actingAs($user)
            ->post(route('student.membership.cancel'))
            ->assertRedirect();

        $membership = ClubMembership::where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($membership->renewal_cancelled_at);
        $this->assertNull($membership->revoked_at, 'отказ от продления не отбирает оплаченный период');
        $this->assertTrue($membership->isActive());
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'группа остаётся до конца оплаченного срока'
        );
    }

    public function test_cancelled_member_keeps_the_shelf_until_the_period_ends(): void
    {
        $user = $this->clubMember();
        $this->actingAs($user)->post(route('student.membership.cancel'));

        $this->actingAs($user->fresh())
            ->get(route('student.course', $this->shelfCourse->slug))
            ->assertOk();
    }

    public function test_resume_clears_the_intent(): void
    {
        $user = $this->clubMember();
        $this->actingAs($user)->post(route('student.membership.cancel'));

        $this->actingAs($user)->post(route('student.membership.resume'))->assertRedirect();

        $this->assertNull(ClubMembership::where('user_id', $user->id)->firstOrFail()->renewal_cancelled_at);
    }

    public function test_cancel_without_a_membership_reports_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('student.membership.cancel'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_routes_are_404_while_the_flag_is_off(): void
    {
        config()->set('features.membership_cancellation', false);
        $user = $this->clubMember();

        $this->actingAs($user)->post(route('student.membership.cancel'))->assertNotFound();
        $this->actingAs($user)->post(route('student.membership.resume'))->assertNotFound();
    }

    public function test_cabinet_states_that_the_entitlement_ends_not_the_access(): void
    {
        // §2.4: ссылки на видео unlisted и после конца подписки продолжают
        // работать. Обещать их отзыв — это заявление, из которого вырастает
        // спор о возврате денег, поэтому текст обязан говорить о ПРАВЕ.
        $user = $this->clubMember();

        $response = $this->actingAs($user)->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('право доступа', false);
        $response->assertSee('мы у вас не отбираем', false);
        $response->assertSee('Не продлевать', false);
        $response->assertDontSee('доступ будет отозван', false);
    }

    public function test_cabinet_shows_nothing_club_related_to_a_non_member(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Записи клуба', false);
    }

    public function test_cancel_marks_every_live_period_of_the_member(): void
    {
        $user = $this->clubMember();
        $this->payClub($user, 1); // продление встык — второй живой период

        $this->actingAs($user->fresh())->post(route('student.membership.cancel'));

        $this->assertSame(0, ClubMembership::where('user_id', $user->id)
            ->whereNull('renewal_cancelled_at')->count(),
            'иначе «не продлевать» гасило бы только ближайший период');
    }
}
