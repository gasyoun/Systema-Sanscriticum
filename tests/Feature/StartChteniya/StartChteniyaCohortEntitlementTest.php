<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\StartChteniyaCohort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2105 — «Старт чтения» cohort funnel: SKU + enrollment + Tochka grant.
 *
 * NOT a new money/access system — the cohort is an ordinary Course+Tariff sold
 * through the existing generic checkout and unlocked through the existing
 * PaymentObserver::grantAccess() course_group pivot (D10). These tests pin the
 * pay→grant→entitlement chain for App\Support\StartChteniyaCohort, the group/
 * schedule hook (reuse of Course::groups()), and the R20 marathon fence (D12).
 */
class StartChteniyaCohortEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function cohortCourse(): Course
    {
        $course = Course::factory()->create(['slug' => 'start-chteniya']);
        config(['start_chteniya.course_slug' => $course->slug]);

        return $course;
    }

    /** @test */
    public function feature_flag_off_denies_entitlement_even_for_a_real_paid_student(): void
    {
        config(['features.start_chteniya_cohort' => false]);

        $course = $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertFalse(
            StartChteniyaCohort::hasEntitlement($user->fresh()),
            'Deploy switch OFF must deny entitlement regardless of payment (prod-inert until ops enables it).'
        );
    }

    /** @test */
    public function unpaid_user_has_no_entitlement(): void
    {
        config(['features.start_chteniya_cohort' => true]);

        $this->cohortCourse();
        $user = User::factory()->create();

        $this->assertFalse(StartChteniyaCohort::hasEntitlement($user));
        $this->assertFalse(StartChteniyaCohort::hasEntitlement(null));
    }

    /** @test */
    public function paying_via_the_generic_checkout_grants_entitlement_and_the_course_group(): void
    {
        config(['features.start_chteniya_cohort' => true]);

        $course = $this->cohortCourse();
        $group = Group::create(['name' => 'Старт чтения — поток 1']);
        $course->groups()->attach($group->id);

        $tariff = Tariff::factory()->for($course)->create([
            'type' => 'full',
            'price' => 9990,
        ]);

        $user = User::factory()->create();

        // Full generic checkout — no bespoke cohort route, exactly like any other course.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => (float) $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
        ]);

        $fresh = $user->fresh();

        $this->assertTrue(StartChteniyaCohort::hasEntitlement($fresh));
        $this->assertContains(
            $group->id,
            $fresh->groups->pluck('id')->all(),
            'grantAccess() must add the paid student to the cohort group/schedule slot.'
        );
        $this->assertTrue(
            $fresh->courses()->where('courses.id', $course->id)->exists(),
            'enrollInCourse() must mark the student as enrolled (course_user pivot).'
        );
    }

    /** @test */
    public function deposit_and_trial_payments_do_not_grant_entitlement(): void
    {
        config(['features.start_chteniya_cohort' => true]);

        $course = $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertFalse(
            StartChteniyaCohort::hasEntitlement($user->fresh()),
            'Fail = grants access without a real course payment — deposit/trial rows must never count.'
        );
    }

    /** @test */
    public function conditional_promised_access_does_not_grant_entitlement(): void
    {
        config(['features.start_chteniya_cohort' => true]);

        $course = $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => true,
        ]);

        $this->assertFalse(
            StartChteniyaCohort::hasEntitlement($user->fresh()),
            'Access granted "under promise" (is_conditional) has no real money behind it and must not count.'
        );
    }

    /** @test */
    public function unpaid_pending_payment_does_not_grant_entitlement(): void
    {
        config(['features.start_chteniya_cohort' => true]);

        $course = $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $this->assertFalse(StartChteniyaCohort::hasEntitlement($user->fresh()));
    }

    /** @test */
    public function enrollment_never_touches_the_r20_marathon_cohort(): void
    {
        // D12 fence: the «Старт чтения» funnel is a completely separate Course/
        // Payment path from the marathon diagnostic funnel (MarathonController /
        // MarathonEnrollment / Payment::isMarathonPaid) — no code here creates or
        // reads a MarathonEnrollment row, and paying for the cohort must not create one.
        config(['features.start_chteniya_cohort' => true]);

        $course = $this->cohortCourse();
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertTrue(StartChteniyaCohort::hasEntitlement($user->fresh()));
        $this->assertSame(0, MarathonEnrollment::query()->count());
    }
}
