<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarathonEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
    }

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
            'name' => 'Анна',
            'contact' => 'student@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $response->assertRedirect(route('marathon.show'));

        $lead = Lead::where('contact', 'student@example.com')->firstOrFail();
        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame('Анна', $lead->name);
        $this->assertSame('free', $enrollment->track);
        $this->assertSame('grammar', $enrollment->quiz_goal);
        $this->assertNotNull($enrollment->day0_started_at);
        $this->assertSame(0, $enrollment->currentDay());
    }

    public function test_registration_paid_track_persists(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), [
            'name' => 'Борис',
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
            'name' => 'Повтор',
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

        $this->post(route('marathon.register'), [
            'name' => 'Без контакта',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ])->assertSessionHasErrors('contact');
    }

    public function test_registration_requires_name(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), [
            'contact' => 'noname@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ])->assertSessionHasErrors('name');
    }

    public function test_registration_rejects_invalid_track(): void
    {
        $this->landing();

        // Separate test, not a second post() in the same test: the 1-request/5s
        // rate limit on this endpoint (RateLimiter::hit(..., 5)) would otherwise
        // 429 the second call instead of reaching validation.
        $this->post(route('marathon.register'), [
            'name' => 'Икс',
            'contact' => 'x@example.com',
            'track' => 'bogus',
            'quiz_goal' => 'grammar',
        ])->assertSessionHasErrors('track');
    }

    public function test_registration_attaches_telegram_magnet_token_and_deep_link(): void
    {
        $this->landing();

        $response = $this->post(route('marathon.register'), [
            'name' => 'Телеграм',
            'contact' => 'telegram-link@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $lead = Lead::where('contact', 'telegram-link@example.com')->firstOrFail();
        $this->assertNotNull($lead->magnet_token);
        $this->assertSame('telegram', $lead->magnet_channel);

        $response->assertSessionHas('marathon_telegram_link', fn ($link) => str_contains($link, $lead->magnet_token)
            && str_contains($link, 't.me/samskrte_bot'));
    }

    public function test_duplicate_registration_reuses_existing_magnet_token(): void
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create([
            'contact' => 'repeat-token@example.com',
            'landing_page_id' => $landing->id,
            'magnet_token' => 'EXISTINGTOKEN',
            'magnet_channel' => 'telegram',
        ]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);

        $this->post(route('marathon.register'), [
            'name' => 'Повтор',
            'contact' => 'repeat-token@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $lead->refresh();
        $this->assertSame('EXISTINGTOKEN', $lead->magnet_token);
    }

    public function test_current_day_computed_from_registration_moment(): void
    {
        $enrollment = MarathonEnrollment::factory()->create(['day0_started_at' => now()->subDays(2)]);
        $this->assertSame(2, $enrollment->currentDay());

        $farFuture = MarathonEnrollment::factory()->create(['day0_started_at' => now()->subDays(30)]);
        $this->assertSame(3, $farFuture->currentDay());
    }

    /** H445 Phase 1 — this route only ever serves the August landing today. */
    public function test_registration_defaults_to_zero_cohort(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), [
            'name' => 'Когорта',
            'contact' => 'cohort-default@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $enrollment = MarathonEnrollment::whereHas('lead', fn ($q) => $q->where('contact', 'cohort-default@example.com'))->firstOrFail();
        $this->assertSame(MarathonEnrollment::COHORT_ZERO, $enrollment->cohort);
        $this->assertFalse($enrollment->isDevaCohort());
    }

    public function test_content_falls_back_to_shared_default_when_cohort_has_no_override(): void
    {
        $enrollment = MarathonEnrollment::factory()->deva()->make();

        $this->assertTrue($enrollment->isDevaCohort());
        // Day 3 (host/schedule) is design-permanent shared content -- see
        // config/marathon.php's H445 Phase 1 comment -- unlike day1_message,
        // which H445 Phase 3 gave a real `deva` override to.
        $this->assertSame(config('marathon.day3_message_paid'), $enrollment->content('day3_message_paid'));
    }

    public function test_content_prefers_cohort_override_when_present(): void
    {
        config(['marathon.cohorts.deva.day1_message' => 'деванагари день 1']);

        $zero = MarathonEnrollment::factory()->make();
        $deva = MarathonEnrollment::factory()->deva()->make();

        $this->assertSame(config('marathon.day1_message'), $zero->content('day1_message'));
        $this->assertSame('деванагари день 1', $deva->content('day1_message'));
    }

    // --- H447 §5 leaderboard A/B: arm is assigned once at enrolment and never flips ---

    public function test_registration_assigns_an_ab_arm(): void
    {
        $this->landing();

        $this->post(route('marathon.register'), [
            'name' => 'АБ',
            'contact' => 'arm@example.com',
            'track' => 'free',
            'quiz_goal' => 'grammar',
        ]);

        $lead = Lead::where('contact', 'arm@example.com')->firstOrFail();
        $enrollment = MarathonEnrollment::where('lead_id', $lead->id)->firstOrFail();

        $this->assertContains($enrollment->ab_arm, [MarathonEnrollment::ARM_A, MarathonEnrollment::ARM_B]);
        $this->assertSame(MarathonEnrollment::computeArm($lead->id), $enrollment->ab_arm);
    }

    public function test_ab_arm_is_stable_across_repeated_computation(): void
    {
        // "Across sessions" = recomputing later (a fresh session/request)
        // for the same identity must always land on the same arm.
        $lead = Lead::factory()->create();

        $arm1 = MarathonEnrollment::computeArm($lead->id);
        $arm2 = MarathonEnrollment::computeArm($lead->id);
        $arm3 = MarathonEnrollment::computeArm($lead->id);

        $this->assertSame($arm1, $arm2);
        $this->assertSame($arm1, $arm3);
    }

    public function test_duplicate_registration_does_not_change_the_assigned_arm(): void
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create(['contact' => 'repeat-arm@example.com', 'landing_page_id' => $landing->id]);
        $enrollment = MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'ab_arm' => MarathonEnrollment::ARM_B,
        ]);

        $this->post(route('marathon.register'), [
            'name' => 'Повтор',
            'contact' => 'repeat-arm@example.com',
            'track' => 'free',
            'quiz_goal' => 'philo',
        ]);

        $enrollment->refresh();
        $this->assertSame(MarathonEnrollment::ARM_B, $enrollment->ab_arm);
    }

    public function test_arm_split_is_roughly_fifty_fifty_across_many_ids(): void
    {
        $arms = collect(range(1, 500))->map(fn (int $id) => MarathonEnrollment::computeArm($id));

        $countA = $arms->filter(fn ($a) => $a === MarathonEnrollment::ARM_A)->count();
        $countB = $arms->filter(fn ($a) => $a === MarathonEnrollment::ARM_B)->count();

        $this->assertSame(500, $countA + $countB);
        // Not asserting an exact 250/250 split (hash-dependent) — just that
        // neither arm dominates, i.e. the split is genuinely ~50/50.
        $this->assertGreaterThan(150, $countA);
        $this->assertGreaterThan(150, $countB);
    }

    public function test_reset_quiz_engagement_clears_day1_only(): void
    {
        $enrollment = MarathonEnrollment::factory()->create([
            'day1_engaged_at' => now(),
            'day1_quiz_seconds' => 90,
            'day2_engaged_at' => now(),
            'day2_quiz_seconds' => 40,
            'day2_question' => 'Сохранить?',
        ]);

        $enrollment->resetQuizEngagement(1);
        $enrollment->refresh();

        $this->assertNull($enrollment->day1_engaged_at);
        $this->assertNull($enrollment->day1_quiz_seconds);
        $this->assertNotNull($enrollment->day2_engaged_at);
        $this->assertSame(40, $enrollment->day2_quiz_seconds);
        $this->assertSame('Сохранить?', $enrollment->day2_question);
    }

    public function test_reset_quiz_engagement_both_days(): void
    {
        $enrollment = MarathonEnrollment::factory()->create([
            'day1_engaged_at' => now(),
            'day1_quiz_seconds' => 10,
            'day2_engaged_at' => now(),
            'day2_quiz_seconds' => 20,
        ]);

        $enrollment->resetQuizEngagement(null);
        $enrollment->refresh();

        $this->assertNull($enrollment->day1_engaged_at);
        $this->assertNull($enrollment->day2_engaged_at);
        $this->assertNull($enrollment->day1_quiz_seconds);
        $this->assertNull($enrollment->day2_quiz_seconds);
    }

    public function test_warm_tail_wave_defaults_to_flagship_without_a_cutoff(): void
    {
        config(['marathon.warm_tail_wave2_from' => null]);

        $enrollment = MarathonEnrollment::factory()->create();

        $this->assertSame(MarathonEnrollment::WAVE_FLAGSHIP, $enrollment->warmTailWave());
    }

    public function test_warm_tail_wave_follows_the_enrolment_moment_against_the_cutoff(): void
    {
        config(['marathon.warm_tail_wave2_from' => '2026-09-10']);

        $before = MarathonEnrollment::factory()->create(['day0_started_at' => '2026-09-09 23:00']);
        $onCutoff = MarathonEnrollment::factory()->create(['day0_started_at' => '2026-09-10 00:30']);

        $this->assertSame(MarathonEnrollment::WAVE_FLAGSHIP, $before->warmTailWave());
        $this->assertSame(MarathonEnrollment::WAVE_MEMBERSHIP, $onCutoff->warmTailWave());
    }
}
