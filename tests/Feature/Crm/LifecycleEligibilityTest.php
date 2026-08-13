<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\ActivityEvent;
use App\Models\Campaign;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\Crm\Lifecycle\LifecycleEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2484 — shared eligibility query: recovery/suppression/dedup gates
 * and dry-run/live user-id parity.
 */
class LifecycleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function eligibility(): LifecycleEligibility
    {
        return app(LifecycleEligibility::class);
    }

    private function pendingCheckout(User $user, Course $course, int $hoursAgo = 5): Payment
    {
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ]));
        $payment->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $payment->fresh();
    }

    private function paid(User $user, Course $course, int $hoursAgo = 48): Payment
    {
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        $payment->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $payment->fresh();
    }

    /** @test */
    public function uncompleted_checkout_includes_stale_pending_and_skips_later_paid(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $eligible = User::factory()->create([
            'email' => 'open@example.test',
            'wants_email_announcements' => true,
        ]);
        $paidLater = User::factory()->create([
            'email' => 'paid@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->pendingCheckout($eligible, $course, 5);
        $this->pendingCheckout($paidLater, $course, 5);
        $this->paid($paidLater, $course, 1);

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);

        $this->assertSame([$eligible->id], $census->eligibleUsers->pluck('id')->all());
    }

    /** @test */
    public function missing_first_cabinet_skips_users_who_already_acted(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $missing = User::factory()->create([
            'email' => 'missing@example.test',
            'wants_email_announcements' => true,
        ]);
        $acted = User::factory()->create([
            'email' => 'acted@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->paid($missing, $course, 48);
        $this->paid($acted, $course, 48);
        ActivityEvent::create([
            'user_id' => $acted->id,
            'event_type' => ActivityEvent::FIRST_CABINET_ACTION,
            'created_at' => now()->subDay(),
        ]);

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_MISSING_FIRST_CABINET);

        $this->assertSame([$missing->id], $census->eligibleUsers->pluck('id')->all());
    }

    /** @test */
    public function next_product_requires_successor_learning_and_no_next_order(): void
    {
        $begin = Course::factory()->create(['is_active' => true, 'title' => 'Begin']);
        $next = Course::factory()->create([
            'is_active' => true,
            'title' => 'Continue',
            'predecessor_course_id' => $begin->id,
        ]);
        $ready = User::factory()->create([
            'email' => 'ready@example.test',
            'wants_email_announcements' => true,
        ]);
        $already = User::factory()->create([
            'email' => 'has-next@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->paid($ready, $begin, 72);
        $this->paid($already, $begin, 72);
        $this->paid($already, $next, 10);
        ActivityEvent::create([
            'user_id' => $ready->id,
            'event_type' => ActivityEvent::FIRST_CABINET_ACTION,
            'created_at' => now()->subDay(),
        ]);
        ActivityEvent::create([
            'user_id' => $already->id,
            'event_type' => ActivityEvent::FIRST_CABINET_ACTION,
            'created_at' => now()->subDay(),
        ]);

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_NEXT_PRODUCT);

        $this->assertSame([$ready->id], $census->eligibleUsers->pluck('id')->all());
    }

    /** @test */
    public function recovery_and_suppression_never_land_in_eligible(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $ok = User::factory()->create([
            'email' => 'ok@example.test',
            'wants_email_announcements' => true,
        ]);
        $suppressed = User::factory()->create([
            'email' => 'nope@example.test',
            'wants_email_announcements' => true,
        ]);
        $recovery = User::factory()->create([
            'email' => 'fault@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->pendingCheckout($ok, $course, 5);
        $this->pendingCheckout($suppressed, $course, 5);
        $this->pendingCheckout($recovery, $course, 5);
        SuppressedEmail::suppress('nope@example.test', 'unsubscribe');
        PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $recovery->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(10),
            'amount' => 3000,
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]));

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);

        $this->assertSame([$ok->id], $census->eligibleUsers->pluck('id')->all());
        $this->assertContains($suppressed->id, $census->suppressedUserIds);
        $this->assertContains($recovery->id, $census->recoveryUserIds);
    }

    /** @test */
    public function already_sent_rule_version_is_deduplicated(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => 'again@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->pendingCheckout($user, $course, 5);
        $campaign = Campaign::query()->create([
            'subject' => 'prior',
            'body_html' => '<p>x</p>',
            'segment' => [
                'type' => 'lifecycle',
                'rule' => LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT,
                'version' => 1,
            ],
            'status' => Campaign::STATUS_SENT,
        ]);
        $campaign->recipients()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'sent_at' => now()->subDay(),
        ]);

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);

        $this->assertSame([], $census->eligibleUsers->pluck('id')->all());
        $this->assertContains($user->id, $census->deduplicatedUserIds);
    }

    /** @test */
    public function dry_run_and_live_eligible_ids_are_identical(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => 'parity@example.test',
            'wants_email_announcements' => true,
        ]);
        $this->pendingCheckout($user, $course, 5);

        $dry = $this->eligibility()->evaluate(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);
        $live = $this->eligibility()->eligibleUsers(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);

        $this->assertSame(
            $dry->eligibleUsers->pluck('id')->all(),
            $live->pluck('id')->all()
        );
    }

    /** @test */
    public function deposit_tariff_is_outside_the_qualifying_denominator(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => 'deposit@example.test',
            'wants_email_announcements' => true,
        ]);
        $deposit = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2000,
            'tariff' => 'deposit',
            'status' => 'pending',
            'is_conditional' => false,
        ]));
        $deposit->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

        $census = $this->eligibility()->evaluate(LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);

        $this->assertSame(0, $census->candidateCount);
        $this->assertTrue($census->eligibleUsers->isEmpty());
    }
}
