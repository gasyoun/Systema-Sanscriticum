<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

/**
 * Право клубного члена на каталог (H2644) — и три границы, которые оно не
 * переходит: курс вне полки, истёкшее членство, проверка домашних заданий.
 */
class ClubEntitlementAccessTest extends MembershipTestCase
{
    private function clubMember(): User
    {
        $user = User::factory()->create();
        $this->payClub($user, 1);

        return $user->fresh();
    }

    public function test_club_member_opens_a_shelf_course_without_being_in_its_group(): void
    {
        $user = $this->clubMember();

        $this->assertFalse(
            $this->shelfCourse->groups()->whereIn('groups.id', $user->groups->pluck('id'))->exists(),
            'предпосылка теста: клубный член НЕ состоит в группе курса полки'
        );

        $this->actingAs($user)
            ->get(route('student.course', $this->shelfCourse->slug))
            ->assertOk();
    }

    public function test_club_member_can_watch_a_paid_lesson_of_a_shelf_course(): void
    {
        $user = $this->clubMember();

        $this->actingAs($user)
            ->get(route('student.lesson', [$this->shelfCourse->slug, $this->shelfLesson->id]))
            ->assertOk();
    }

    public function test_non_member_cannot_open_a_shelf_course(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.course', $this->shelfCourse->slug))
            ->assertNotFound();
    }

    public function test_lapsed_member_loses_the_shelf(): void
    {
        $user = $this->clubMember();
        ClubMembership::where('user_id', $user->id)->update([
            'ends_at' => now()->subDays(5),
            'grace_until' => now()->subDays(2),
        ]);

        $this->actingAs($user->fresh())
            ->get(route('student.course', $this->shelfCourse->slug))
            ->assertNotFound();
    }

    public function test_course_outside_the_shelf_stays_closed_to_a_club_member(): void
    {
        $user = $this->clubMember();
        $outside = Course::factory()->create(['club_included' => false]);
        $group = Group::create(['name' => 'Вне полки']);
        $outside->groups()->attach($group->id);

        $this->actingAs($user)
            ->get(route('student.course', $outside->slug))
            ->assertNotFound();
    }

    public function test_membership_course_itself_is_not_in_its_own_shelf(): void
    {
        $user = $this->clubMember();
        $this->clubCourse->forceFill(['club_included' => true])->save();

        $entitlement = app(ClubEntitlement::class);

        $this->assertFalse($entitlement->coversCourse($user, $this->clubCourse->fresh()),
            'курс-членство — товар, а не содержимое; иначе клуб «открывал» бы сам себя');
        $this->assertFalse($entitlement->shelfFor($user)->contains('id', $this->clubCourse->id));
    }

    public function test_club_gives_no_homework_review(): void
    {
        // §6: «no homework review · no curator time». Гейт домашних заданий
        // НАМЕРЕННО не знает про клуб — этим запрет и исполняется.
        $user = $this->clubMember();
        $this->shelfLesson->forceFill(['homework_enabled' => true])->save();

        $this->actingAs($user)
            ->post(route('student.homework.store', [$this->shelfCourse->slug, $this->shelfLesson->id]), [
                'comment' => 'проверьте, пожалуйста',
            ])
            ->assertForbidden();
    }

    public function test_flag_off_removes_the_entitlement_entirely(): void
    {
        $user = $this->clubMember();
        config()->set('features.club_membership', false);

        $entitlement = app(ClubEntitlement::class);

        $this->assertFalse($entitlement->isMember($user));
        $this->assertSame([], $entitlement->extraTariffKeys($user, $this->shelfCourse));
        $this->actingAs($user)
            ->get(route('student.course', $this->shelfCourse->slug))
            ->assertNotFound();
    }

    public function test_shelf_lists_only_active_included_courses(): void
    {
        $user = $this->clubMember();
        Course::factory()->create(['club_included' => true, 'is_active' => false]);
        Course::factory()->create(['club_included' => false]);

        $shelf = app(ClubEntitlement::class)->shelfFor($user);

        $this->assertSame([$this->shelfCourse->id], $shelf->pluck('id')->all());
    }

    public function test_paid_course_access_is_unaffected_by_club_state(): void
    {
        // Клуб ортогонален покупке курса: оплаченный курс открыт и без клуба.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Оплаченный поток']);
        $course->groups()->attach($group->id);
        $user->groups()->syncWithoutDetaching([$group->id]);
        Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 1]);
        \App\Models\Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 12000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->actingAs($user->fresh())
            ->get(route('student.course', $course->slug))
            ->assertOk();
    }
}
