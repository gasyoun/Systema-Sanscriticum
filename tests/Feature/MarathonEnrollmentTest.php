<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarathonEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function landing(): LandingPage
    {
        return LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
    }

    public function test_show_page_loads_with_no_landing_row_yet(): void
    {
        // Pre-launch state: the LandingPage row doesn't exist until an admin
        // creates it via Filament — the page must still render, not 500.
        $this->get(route('marathon.show'))->assertOk();
    }

    public function test_registration_creates_lead_and_enrollment_free_track(): void
    {
        $this->landing();

        $response = $this->post(route('marathon.register'), [
            'contact' => 'student@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $response->assertRedirect(route('marathon.show'));

        $lead = Lead::where('contact', 'student@example.com')->firstOrFail();
        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame('free', $enrollment->track);
        $this->assertSame('grammar', $enrollment->quiz_goal);
        $this->assertNotNull($enrollment->day0_started_at);
        $this->assertSame(0, $enrollment->currentDay());
    }

    public function test_registration_paid_track_persists(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), [
            'contact' => 'paid@example.com',
            'track' => 'paid',
            'quiz_goal' => 'yoga',
        ]);

        $enrollment = MarathonEnrollment::whereHas('lead', fn ($q) => $q->where('contact', 'paid@example.com'))->firstOrFail();
        $this->assertTrue($enrollment->isPaidTrack());
    }

    public function test_duplicate_registration_does_not_reset_personal_day_clock(): void
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create(['contact' => 'repeat@example.com', 'landing_page_id' => $landing->id]);
        $enrollment = MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'day0_started_at' => now()->subDays(2),
        ]);
        $originalStart = $enrollment->day0_started_at;

        $this->post(route('marathon.register'), [
            'contact' => 'repeat@example.com',
            'track' => 'free',
            'quiz_goal' => 'philo',
        ]);

        $this->assertSame(1, MarathonEnrollment::where('lead_id', $lead->id)->count());
        $enrollment->refresh();
        $this->assertEquals($originalStart->timestamp, $enrollment->day0_started_at->timestamp);
    }

    public function test_registration_requires_contact(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), ['track' => 'free', 'quiz_goal' => 'grammar'])
            ->assertSessionHasErrors('contact');
    }

    public function test_registration_rejects_invalid_track(): void
    {
        $this->landing();

        // Separate test, not a second post() in the same test: the 1-request/5s
        // rate limit on this endpoint (RateLimiter::hit(..., 5)) would otherwise
        // 429 the second call instead of reaching validation.
        $this->post(route('marathon.register'), ['contact' => 'x@example.com', 'track' => 'bogus', 'quiz_goal' => 'grammar'])
            ->assertSessionHasErrors('track');
    }

    public function test_current_day_computed_from_registration_moment(): void
    {
        $enrollment = MarathonEnrollment::factory()->create(['day0_started_at' => now()->subDays(2)]);
        $this->assertSame(2, $enrollment->currentDay());

        $farFuture = MarathonEnrollment::factory()->create(['day0_started_at' => now()->subDays(30)]);
        $this->assertSame(3, $farFuture->currentDay());
    }
}
