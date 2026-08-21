<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * G31 / H3204 — remaining two clauses after H3190's quiz-intent routing test.
 *
 * 1. Page + checkout smoke for the ₽ consultation product. Price and title
 *    are read off config / the rendered page, never restated here — a fixture
 *    that copies «500» and the product name would stay green after a rename.
 * 2. Launch-date gating: student-facing «день старта» channel exposure and
 *    the zero-cohort Days 1–2 stay pinned to config('marathon.launch_date');
 *    Devanagari is the January cohort, not the 28-08 Cyrillic intake.
 *
 * Filename deliberately has no «Marathon» token so G42's glob battery does
 * not grow. `--filter=Consultation` is the G31 residual signal.
 */
class ConsultationProductLaunchGateTest extends TestCase
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
            'title' => 'consultation-landing-fixture',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
    }

    private function startPostEvent(): ?Event
    {
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            $command = (string) $event->command;
            if (str_contains($command, 'marathon:publish-channel-posts')
                && str_contains($command, '--post=2')) {
                return $event;
            }
        }

        return null;
    }

    private function launchDate(): Carbon
    {
        return Carbon::parse((string) config('marathon.launch_date'), 'Europe/Moscow');
    }

    private function assertNoDevanagari(mixed $value, string $where): void
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($text, $where.' did not stringify.');
        $this->assertDoesNotMatchRegularExpression(
            '/\p{Devanagari}/u',
            $text,
            $where.' leaks Devanagari into the zero-cohort (Cyrillic-only) path.'
        );
    }

    public function test_consultation_page_smokes_and_renders_the_config_price(): void
    {
        $this->landing();
        $price = (int) config('marathon.paid_track_price');
        $this->assertGreaterThan(0, $price, 'Paid-track price must come from config, not a test fixture.');

        $response = $this->get(route('marathon.show'));
        $response->assertOk();
        $response->assertViewHas('paidTrackPrice', $price);
        $response->assertSee((string) $price, false);
        $response->assertSee(route('marathon.register'), false);

        preg_match('/<title>(.*?)<\/title>/su', $response->getContent(), $match);
        $this->assertNotEmpty($match[1] ?? '', 'The consultation page rendered without a title.');
        $title = html_entity_decode(trim(strip_tags($match[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertNotSame('', $title);
        $this->assertDoesNotMatchRegularExpression(
            '/\p{Devanagari}/u',
            $title,
            'Zero-cohort page title must stay Cyrillic; Devanagari is the January landing.'
        );
    }

    public function test_consultation_checkout_smokes_using_the_config_amount(): void
    {
        $landing = $this->landing();
        $lead = Lead::factory()->create([
            'contact' => 'g31-payer@example.test',
            'landing_page_id' => $landing->id,
        ]);
        MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'track' => MarathonEnrollment::TRACK_PAID,
        ]);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/g31-consultation', 'paymentLinkId' => 'pl_g31'],
        ], 200)]);

        $this->post(route('marathon.pay'), [
            'contact' => 'g31-payer@example.test',
            'email' => 'g31-payer@example.test',
        ])->assertRedirect('https://pay.tochka/g31-consultation');

        $this->assertDatabaseHas('payments', [
            'tariff' => 'marathon_paid',
            'status' => 'pending',
            'amount' => config('marathon.paid_track_price'),
            'course_id' => null,
        ]);
    }

    public function test_launch_date_is_the_28_august_2026_cohort_zero_intake(): void
    {
        $launch = $this->launchDate();

        $this->assertSame(
            '2026-08-28',
            $launch->toDateString(),
            'Student-facing marathon launch must stay 2026-08-28 until a human re-dates the cohort.'
        );

        $when = (string) (config('marathon_landing_copy.channel_posts.2.when') ?? '');
        $this->assertStringContainsString(
            $launch->format('d-m-Y'),
            $when,
            'Channel post 2 (день старта) copy drifted off config(marathon.launch_date).'
        );

        $january = Carbon::parse((string) config('marathon.january_launch_date'), 'Europe/Moscow');
        $this->assertTrue(
            $january->greaterThan($launch),
            'January Devanagari cohort must launch after the Cyrillic zero-cohort intake.'
        );
    }

    public function test_student_facing_start_channel_post_is_gated_to_the_launch_date(): void
    {
        $event = $this->startPostEvent();
        $this->assertNotNull($event, 'marathon:publish-channel-posts --post=2 is not on the Kernel schedule.');

        $launch = $this->launchDate();
        $this->assertSame(
            sprintf('0 10 %d %d *', $launch->day, $launch->month),
            $event->expression,
            'Start-post cron must be derived from config(marathon.launch_date), not a magic 28 8.'
        );

        Carbon::setTestNow($launch->copy()->setTime(10, 0));
        $this->assertTrue($event->isDue($this->app), 'Start post must be due on launch morning (10:00 MSK).');
        $this->assertTrue($event->filtersPass($this->app), 'Start post when() must pass on the launch date.');

        Carbon::setTestNow($launch->copy()->subDay()->setTime(10, 0));
        $this->assertFalse(
            $event->isDue($this->app),
            'Start post must not fire the day before launch (no early publish).'
        );

        // Cron has no year; the when() callback is what pins 2026-08-28 specifically.
        Carbon::setTestNow($launch->copy()->addYear()->setTime(10, 0));
        $this->assertFalse(
            $event->filtersPass($this->app),
            'Start post must not re-fire on a later 28 August; launch_date is a one-shot gate.'
        );

        Carbon::setTestNow();
    }

    public function test_zero_cohort_days_one_and_two_are_cyrillic_only(): void
    {
        foreach (['day1_message', 'day2_message', 'day1_quiz', 'day2_quiz'] as $key) {
            $this->assertNoDevanagari(config('marathon.'.$key), 'config(marathon.'.$key.')');
        }

        $deva = json_encode(config('marathon.cohorts.deva'), JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($deva, 'January overlay did not stringify.');
        $this->assertNotSame('', (string) config('marathon.cohorts.deva.day1_message', ''),
            'January overlay is missing — gating has nothing to contrast against.');
        $this->assertMatchesRegularExpression(
            '/\p{Devanagari}/u',
            (string) $deva,
            'Devanagari script belongs on the January cohort overlay, not on the August zero-cohort days.'
        );
    }
}
