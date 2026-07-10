<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LandingPage;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarathonPaidCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function landing(): LandingPage
    {
        return LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
    }

    /** @return array{0: Lead, 1: MarathonEnrollment} */
    private function paidTrackEnrollment(string $contact = 'payer@example.test'): array
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create(['contact' => $contact, 'landing_page_id' => $landing->id]);
        $enrollment = MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'track' => MarathonEnrollment::TRACK_PAID,
        ]);

        return [$lead, $enrollment];
    }

    public function test_paid_track_checkout_creates_pending_payment_and_redirects(): void
    {
        [, ] = $this->paidTrackEnrollment();

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/marathon1', 'paymentLinkId' => 'pl_marathon1'],
        ], 200)]);

        $this->post(route('marathon.pay'), [
            'contact' => 'payer@example.test',
            'email' => 'payer@example.test',
        ])->assertRedirect('https://pay.tochka/marathon1');

        $this->assertDatabaseHas('payments', [
            'tariff' => 'marathon_paid',
            'status' => 'pending',
            'amount' => config('marathon.paid_track_price'),
            'course_id' => null,
        ]);
        $this->assertDatabaseHas('users', ['email' => 'payer@example.test']);
    }

    public function test_free_track_enrollment_cannot_pay(): void
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create(['contact' => 'freebie@example.test', 'landing_page_id' => $landing->id]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_FREE]);

        $this->post(route('marathon.pay'), [
            'contact' => 'freebie@example.test',
            'email' => 'freebie@example.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
    }

    public function test_unknown_contact_cannot_pay(): void
    {
        $this->landing();

        $this->post(route('marathon.pay'), [
            'contact' => 'nobody@example.test',
            'email' => 'nobody@example.test',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
    }

    public function test_already_paid_enrollment_does_not_charge_twice(): void
    {
        [, $enrollment] = $this->paidTrackEnrollment('already-paid@example.test');
        $enrollment->update(['paid_at' => now()]);

        Http::fake(['*' => Http::response(['Data' => ['paymentLink' => 'https://pay.tochka/x', 'paymentLinkId' => 'x']], 200)]);

        $this->post(route('marathon.pay'), [
            'contact' => 'already-paid@example.test',
            'email' => 'already-paid@example.test',
        ])->assertRedirect(route('marathon.show'));

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
        Http::assertNothingSent();
    }

    public function test_guest_with_existing_email_is_rejected(): void
    {
        [, ] = $this->paidTrackEnrollment('newcomer@example.test');
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('marathon.pay'), [
            'contact' => 'newcomer@example.test',
            'email' => 'taken@example.test',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
    }

    public function test_paid_webhook_marks_enrollment_paid_and_converts_lead(): void
    {
        [$lead, $enrollment] = $this->paidTrackEnrollment('webhook-payer@example.test');
        $user = User::factory()->create(['email' => 'webhook-payer-user@example.test']);

        $payment = Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => null,
            'amount' => config('marathon.paid_track_price'),
            'tariff' => 'marathon_paid',
            'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        $enrollment->refresh();
        $this->assertNotNull($enrollment->paid_at);
        $this->assertTrue($enrollment->isPaidConfirmed());

        $this->assertNotNull($lead->fresh()->converted_at);
    }

    public function test_paid_webhook_is_idempotent_on_paid_at(): void
    {
        [$lead, $enrollment] = $this->paidTrackEnrollment('idempotent-payer@example.test');
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => null,
            'amount' => config('marathon.paid_track_price'),
            'tariff' => 'marathon_paid',
            'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        $firstPaidAt = $enrollment->fresh()->paid_at;

        // Re-saving as paid (e.g. a duplicate webhook delivery) must not move the clock.
        $payment->processMarathonPaid();

        $this->assertEquals($firstPaidAt->timestamp, $enrollment->fresh()->paid_at->timestamp);
    }

    public function test_marathon_paid_grants_no_course_access(): void
    {
        [$lead] = $this->paidTrackEnrollment('no-course-access@example.test');
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => null,
            'amount' => config('marathon.paid_track_price'),
            'tariff' => 'marathon_paid',
            'status' => 'pending',
        ]);
        $payment->update(['status' => 'paid']);

        $this->assertTrue($payment->fresh()->isMarathonPaid());
        $this->assertDatabaseMissing('lesson_access_grants', ['user_id' => $user->id]);
    }
}
