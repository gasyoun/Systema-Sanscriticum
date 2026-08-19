<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H445 Phase 5 — January `deva`-cohort landing (MG ruling 19-08-2026: slug
 * `Janvar-27`, launch 13-01-2027). Mirrors MarathonPaidCheckoutTest's
 * fixture shape, scoped to the separate showJanuary/registerJanuary/
 * payJanuary trio and its own LandingPage row/slug.
 */
class MarathonJanuaryLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function januaryLanding(): LandingPage
    {
        return LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС — деванагари',
            'slug' => config('marathon.january_landing_slug'),
            'is_active' => true,
        ]);
    }

    public function test_show_renders_january_copy_not_zero_cohort_copy(): void
    {
        $this->januaryLanding();

        $response = $this->get(route('marathon.january.show'));

        $response->assertOk();
        $response->assertSee('деванагари', false);
        $response->assertSee(route('marathon.january.register'), false);
    }

    public function test_register_creates_deva_cohort_enrollment_scoped_to_january_landing(): void
    {
        $landing = $this->januaryLanding();

        $this->post(route('marathon.january.register'), [
            'name' => 'Второй Шаг',
            'contact' => 'jan-enrollee@example.test',
            'track' => MarathonEnrollment::TRACK_FREE,
            'quiz_goal' => 'grammar',
        ])->assertRedirect(route('marathon.january.show'));

        $lead = Lead::where('contact', 'jan-enrollee@example.test')->firstOrFail();
        $this->assertSame($landing->id, $lead->landing_page_id);

        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();
        $this->assertTrue($enrollment->isDevaCohort());
        $this->assertNotNull($enrollment->day0_started_at);
    }

    public function test_register_resumes_existing_january_enrollment_without_resetting_clock(): void
    {
        $landing = $this->januaryLanding();
        $lead = Lead::factory()->create(['contact' => 'resume@example.test', 'landing_page_id' => $landing->id]);
        $enrollment = MarathonEnrollment::factory()->deva()->create([
            'lead_id' => $lead->id,
            'day0_started_at' => now()->subDay(),
        ]);
        $originalStart = $enrollment->day0_started_at;

        $this->post(route('marathon.january.register'), [
            'name' => 'Второй Шаг',
            'contact' => 'resume@example.test',
            'track' => MarathonEnrollment::TRACK_FREE,
            'quiz_goal' => 'grammar',
        ])->assertRedirect(route('marathon.january.show'));

        $this->assertSame(1, MarathonEnrollment::where('lead_id', $lead->id)->count());
        $this->assertEquals($originalStart->timestamp, $enrollment->fresh()->day0_started_at->timestamp);
    }

    public function test_january_registration_is_independent_of_zero_cohort_landing(): void
    {
        $zeroLanding = LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
        $this->januaryLanding();

        // Same contact already has a zero-cohort enrollment from the other landing.
        $zeroLead = Lead::factory()->create(['contact' => 'both@example.test', 'landing_page_id' => $zeroLanding->id]);
        MarathonEnrollment::factory()->create(['lead_id' => $zeroLead->id]);

        $this->post(route('marathon.january.register'), [
            'name' => 'Оба Трека',
            'contact' => 'both@example.test',
            'track' => MarathonEnrollment::TRACK_FREE,
            'quiz_goal' => 'grammar',
        ])->assertRedirect(route('marathon.january.show'));

        // A SECOND lead/enrollment pair for the January landing — not a resume of the zero-cohort one.
        $this->assertSame(2, Lead::where('contact', 'both@example.test')->count());
        $this->assertSame(2, MarathonEnrollment::query()->count());
    }

    public function test_pay_january_checkout_creates_pending_payment_and_redirects(): void
    {
        $landing = $this->januaryLanding();
        $lead = Lead::factory()->create(['contact' => 'jan-payer@example.test', 'landing_page_id' => $landing->id]);
        MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_PAID]);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/january1', 'paymentLinkId' => 'pl_january1'],
        ], 200)]);

        $this->post(route('marathon.january.pay'), [
            'contact' => 'jan-payer@example.test',
            'email' => 'jan-payer@example.test',
        ])->assertRedirect('https://pay.tochka/january1');

        $this->assertDatabaseHas('payments', [
            'tariff' => 'marathon_paid',
            'status' => 'pending',
            'amount' => config('marathon.paid_track_price'),
        ]);
    }

    public function test_pay_january_does_not_find_a_zero_cohort_lead_with_the_same_contact(): void
    {
        $zeroLanding = LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
        $this->januaryLanding();

        $lead = Lead::factory()->create(['contact' => 'zero-only@example.test', 'landing_page_id' => $zeroLanding->id]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_PAID]);

        $this->post(route('marathon.january.pay'), [
            'contact' => 'zero-only@example.test',
            'email' => 'zero-only@example.test',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
    }

    public function test_pay_january_already_paid_enrollment_does_not_charge_twice(): void
    {
        $landing = $this->januaryLanding();
        $lead = Lead::factory()->create(['contact' => 'jan-already-paid@example.test', 'landing_page_id' => $landing->id]);
        $enrollment = MarathonEnrollment::factory()->deva()->create([
            'lead_id' => $lead->id,
            'track' => MarathonEnrollment::TRACK_PAID,
            'paid_at' => now(),
        ]);

        Http::fake(['*' => Http::response(['Data' => ['paymentLink' => 'https://pay.tochka/x', 'paymentLinkId' => 'x']], 200)]);

        $this->post(route('marathon.january.pay'), [
            'contact' => 'jan-already-paid@example.test',
            'email' => 'jan-already-paid@example.test',
        ])->assertRedirect(route('marathon.january.show'));

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
        Http::assertNothingSent();
        $this->assertTrue($enrollment->isPaidTrack());
    }

    public function test_pay_january_rejects_taken_email_for_new_user(): void
    {
        $landing = $this->januaryLanding();
        $lead = Lead::factory()->create(['contact' => 'jan-newcomer@example.test', 'landing_page_id' => $landing->id]);
        MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id, 'track' => MarathonEnrollment::TRACK_PAID]);
        User::factory()->create(['email' => 'jan-taken@example.test']);

        $this->post(route('marathon.january.pay'), [
            'contact' => 'jan-newcomer@example.test',
            'email' => 'jan-taken@example.test',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('payments', ['tariff' => 'marathon_paid']);
    }
}
