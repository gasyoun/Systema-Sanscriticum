<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Schedule;
use App\Support\TrialBookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3248 cluster 2 — публичная запись на пробное из виджета.
 * Границы: 404 при выключенных флагах, никаких id/Zoom в ответе,
 * Rank 4 (bookFree не выдаёт доступ), идемпотентность, throttle 5/мин.
 */
class PublicScheduleBookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/schedule/book';

    private function makeTrialSession(): array
    {
        $course = Course::factory()->create(['is_visible' => true]);
        $schedule = Schedule::create([
            'title' => 'Пробное — вводное',
            'course_id' => $course->id,
            'start' => now()->addDays(2)->setTime(20, 0),
            'end' => now()->addDays(2)->setTime(21, 30),
        ]);
        $course->update(['trial_schedule_id' => $schedule->id]);

        return ['course' => $course, 'schedule' => $schedule];
    }

    private function tokenFor(Schedule $schedule): string
    {
        return TrialBookToken::for((int) $schedule->getKey());
    }

    public function test_post_is_404_when_widget_flag_off(): void
    {
        $made = $this->makeTrialSession();

        $this->postJson(self::URL, [
            'book_token' => $this->tokenFor($made['schedule']),
            'email' => 'guest@example.test',
        ])->assertStatus(404);

        $this->assertSame(0, Deal::query()->count());
    }

    public function test_post_is_404_when_booking_flag_off_but_widget_on(): void
    {
        config(['features.crm_trial_widget_public' => true]);
        $made = $this->makeTrialSession();

        $this->postJson(self::URL, [
            'book_token' => $this->tokenFor($made['schedule']),
            'email' => 'guest@example.test',
        ])->assertStatus(404);
    }

    public function test_post_validates_email_and_token(): void
    {
        config([
            'features.crm_trial_widget_public' => true,
            'features.crm_trial_booking' => true,
        ]);
        $made = $this->makeTrialSession();

        // Без e-mail — 422.
        $this->postJson(self::URL, ['book_token' => $this->tokenFor($made['schedule'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Мусорный токен — 422, сделок нет.
        $this->postJson(self::URL, [
            'book_token' => '12.tampered-signature',
            'email' => 'guest@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('book_token');

        $this->assertSame(0, Deal::query()->count());
    }

    public function test_successful_post_creates_lead_and_trial_deal_without_zoom_in_body(): void
    {
        config([
            'features.crm_trial_widget_public' => true,
            'features.crm_trial_booking' => true,
        ]);
        $made = $this->makeTrialSession();

        $response = $this->postJson(self::URL, [
            'book_token' => $this->tokenFor($made['schedule']),
            'email' => 'Guest@Example.test',
            'name' => 'Гость Гостев',
        ]);

        $response->assertOk()->assertExactJson(['ok' => true]);

        // Ни «zoom», ни «http» в теле ответа (VERIFICATION C2).
        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('zoom', $body);
        $this->assertStringNotContainsString('http', $body);

        $this->assertDatabaseCount('deals', 1);
        $deal = Deal::query()->sole();
        $this->assertSame(Deal::KIND_TRIAL, $deal->kind);
        $this->assertSame(Deal::TRIAL_SOURCE_FREE, $deal->trial_source);
        $this->assertSame(Deal::TRIAL_OUTCOME_BOOKED, $deal->trial_outcome);
        $this->assertSame($made['schedule']->id, $deal->schedule_id);

        // Lead найден по email без регистрационной чувствительности; User не создан.
        $this->assertDatabaseCount('leads', 1);
        $lead = Lead::query()->sole();
        $this->assertSame('guest@example.test', strtolower((string) $lead->email));
        $this->assertNull($deal->user_id);
    }

    public function test_second_identical_post_is_idempotent(): void
    {
        config([
            'features.crm_trial_widget_public' => true,
            'features.crm_trial_booking' => true,
        ]);
        $made = $this->makeTrialSession();
        $payload = [
            'book_token' => $this->tokenFor($made['schedule']),
            'email' => 'guest@example.test',
        ];

        $this->postJson(self::URL, $payload)->assertOk();
        $this->postJson(self::URL, $payload)->assertOk();

        $this->assertDatabaseCount('deals', 1);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_throttle_engages_after_five_requests(): void
    {
        config([
            'features.crm_trial_widget_public' => true,
            'features.crm_trial_booking' => true,
        ]);
        $made = $this->makeTrialSession();
        $payload = ['book_token' => 'garbage.token'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $payload)->assertStatus(422);
        }

        $this->postJson(self::URL, $payload)->assertStatus(429);
    }
}
