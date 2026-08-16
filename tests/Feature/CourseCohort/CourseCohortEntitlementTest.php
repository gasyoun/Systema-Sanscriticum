<?php

declare(strict_types=1);

namespace Tests\Feature\CourseCohort;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\CourseCohortEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2164 — slug-parameterized generalization of H2105's StartChteniyaCohort.
 *
 * Same entitlement filter (real, paid, access-granting Payment; never a
 * deposit/trial/conditional/pending row) but keyed by slug in
 * config/cohort_courses.php instead of hardwired to one course. The one
 * behavior H2105 never needed to guard against with only one cohort: a
 * payment on course A must never entitle a student on course B.
 */
class CourseCohortEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function cohortCourse(string $slug, string $configKey = 'nalopakhyana'): Course
    {
        $course = Course::factory()->create(['slug' => $slug]);
        config(["cohort_courses.{$configKey}.course_slug" => $course->slug]);

        return $course;
    }

    private function payment(User $user, Course $course, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ], $overrides));
    }

    /** @test */
    public function feature_flag_off_denies_entitlement_per_slug_independently(): void
    {
        config([
            'cohort_courses.nalopakhyana.enabled' => false,
            'cohort_courses.subhashita.enabled' => true,
        ]);

        $nala = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $subh = $this->cohortCourse('subhashita', 'subhashita');

        $nalaUser = User::factory()->create();
        $this->payment($nalaUser, $nala);

        $subhUser = User::factory()->create();
        $this->payment($subhUser, $subh);

        $this->assertFalse(
            CourseCohortEntitlement::hasEntitlement($nalaUser->fresh(), 'nalopakhyana'),
            'Deploy switch OFF for nalopakhyana must deny entitlement regardless of payment.'
        );
        $this->assertTrue(
            CourseCohortEntitlement::hasEntitlement($subhUser->fresh(), 'subhashita'),
            'subhashita has its own independent deploy switch, ON here, and must not be affected by nalopakhyana being OFF.'
        );
    }

    /** @test */
    public function unknown_slug_denies_entitlement(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(CourseCohortEntitlement::hasEntitlement($user, 'not-a-real-cohort'));
        $this->assertNull(CourseCohortEntitlement::course('not-a-real-cohort'));
    }

    /** @test */
    public function unpaid_user_has_no_entitlement(): void
    {
        config(['cohort_courses.nalopakhyana.enabled' => true]);

        $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();

        $this->assertFalse(CourseCohortEntitlement::hasEntitlement($user, 'nalopakhyana'));
        $this->assertFalse(CourseCohortEntitlement::hasEntitlement(null, 'nalopakhyana'));
    }

    /** @test */
    public function paying_via_the_generic_checkout_grants_entitlement_for_that_slug(): void
    {
        config(['cohort_courses.nalopakhyana.enabled' => true]);

        $course = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $tariff = Tariff::factory()->for($course)->create([
            'type' => 'full',
            'price' => 9990,
        ]);

        $user = User::factory()->create();

        $this->payment($user, $course, [
            'amount' => (float) $tariff->price,
            'tariff' => $tariff->accessKey(),
        ]);

        $this->assertTrue(CourseCohortEntitlement::hasEntitlement($user->fresh(), 'nalopakhyana'));
        $this->assertTrue(
            CourseCohortEntitlement::entitledUsers('nalopakhyana')->pluck('id')->contains($user->id)
        );
    }

    /** @test */
    public function paid_on_course_a_does_not_entitle_course_b(): void
    {
        // The fail condition H2105 never needed to guard against with only
        // one cohort: cross-course entitlement leak.
        config([
            'cohort_courses.nalopakhyana.enabled' => true,
            'cohort_courses.subhashita.enabled' => true,
        ]);

        $nala = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $subh = $this->cohortCourse('subhashita', 'subhashita');

        $user = User::factory()->create();
        $this->payment($user, $nala);

        $fresh = $user->fresh();

        $this->assertTrue(
            CourseCohortEntitlement::hasEntitlement($fresh, 'nalopakhyana'),
            'Paid on nalopakhyana must entitle nalopakhyana.'
        );
        $this->assertFalse(
            CourseCohortEntitlement::hasEntitlement($fresh, 'subhashita'),
            'Fail = cross-course entitlement leak — paying for nalopakhyana must never entitle subhashita.'
        );
        $this->assertFalse(
            CourseCohortEntitlement::entitledUsers('subhashita')->pluck('id')->contains($user->id),
            'entitledUsers() for subhashita must not include a student who only paid for nalopakhyana.'
        );
    }

    /** @test */
    public function deposit_and_trial_payments_do_not_grant_entitlement(): void
    {
        config(['cohort_courses.nalopakhyana.enabled' => true]);

        $course = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();

        $this->payment($user, $course, ['amount' => 1000, 'tariff' => 'deposit']);
        $this->payment($user, $course, ['amount' => 500, 'tariff' => 'trial']);

        $this->assertFalse(
            CourseCohortEntitlement::hasEntitlement($user->fresh(), 'nalopakhyana'),
            'Fail = grants access without a real course payment — deposit/trial rows must never count.'
        );
    }

    /** @test */
    public function conditional_promised_access_does_not_grant_entitlement(): void
    {
        config(['cohort_courses.nalopakhyana.enabled' => true]);

        $course = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();

        $this->payment($user, $course, ['is_conditional' => true]);

        $this->assertFalse(
            CourseCohortEntitlement::hasEntitlement($user->fresh(), 'nalopakhyana'),
            'Access granted "under promise" (is_conditional) has no real money behind it and must not count.'
        );
    }

    /** @test */
    public function unpaid_pending_payment_does_not_grant_entitlement(): void
    {
        config(['cohort_courses.nalopakhyana.enabled' => true]);

        $course = $this->cohortCourse('nalopakhyana', 'nalopakhyana');
        $user = User::factory()->create();

        $this->payment($user, $course, ['status' => 'pending']);

        $this->assertFalse(CourseCohortEntitlement::hasEntitlement($user->fresh(), 'nalopakhyana'));
    }
}
