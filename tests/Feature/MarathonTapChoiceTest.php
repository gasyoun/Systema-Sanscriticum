<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarathonTapChoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Lead, 1: MarathonEnrollment} */
    private function enrollment(): array
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);
        $enrollment = MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);

        return [$lead, $enrollment];
    }

    public function test_day1_page_renders_for_valid_token(): void
    {
        [$lead] = $this->enrollment();

        $this->get(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('День 1');
    }

    public function test_day2_page_renders_for_valid_token(): void
    {
        [$lead] = $this->enrollment();

        $this->get(route('marathon.day', ['day' => 2, 'token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('День 2');
    }

    public function test_day_page_404s_for_unknown_token(): void
    {
        $this->get(route('marathon.day', ['day' => 1, 'token' => 'nonexistent']))
            ->assertNotFound();
    }

    public function test_day_page_404s_when_lead_has_no_enrollment(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);

        $this->get(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]))
            ->assertNotFound();
    }

    public function test_complete_day1_marks_engaged_at(): void
    {
        [$lead, $enrollment] = $this->enrollment();

        $this->post(route('marathon.day.complete', ['day' => 1, 'token' => $lead->magnet_token]))
            ->assertRedirect(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]));

        $this->assertNotNull($enrollment->fresh()->day1_engaged_at);
    }

    public function test_complete_day1_is_idempotent(): void
    {
        [$lead, $enrollment] = $this->enrollment();
        $enrollment->update(['day1_engaged_at' => now()->subHour()]);
        $firstEngagedAt = $enrollment->day1_engaged_at;

        $this->post(route('marathon.day.complete', ['day' => 1, 'token' => $lead->magnet_token]));

        $this->assertEquals($firstEngagedAt->timestamp, $enrollment->fresh()->day1_engaged_at->timestamp);
    }

    public function test_complete_day2_persists_question(): void
    {
        [$lead, $enrollment] = $this->enrollment();

        $this->post(route('marathon.day.complete', ['day' => 2, 'token' => $lead->magnet_token]), [
            'question' => 'С чего мне начать — грамматика или чтение текстов?',
        ]);

        $enrollment->refresh();
        $this->assertNotNull($enrollment->day2_engaged_at);
        $this->assertSame('С чего мне начать — грамматика или чтение текстов?', $enrollment->day2_question);
    }

    public function test_complete_day2_without_question_still_marks_engaged(): void
    {
        [$lead, $enrollment] = $this->enrollment();

        $this->post(route('marathon.day.complete', ['day' => 2, 'token' => $lead->magnet_token]));

        $enrollment->refresh();
        $this->assertNotNull($enrollment->day2_engaged_at);
        $this->assertNull($enrollment->day2_question);
    }

    public function test_complete_day2_does_not_overwrite_existing_question(): void
    {
        [$lead, $enrollment] = $this->enrollment();
        $enrollment->update(['day2_question' => 'Первый вопрос']);

        $this->post(route('marathon.day.complete', ['day' => 2, 'token' => $lead->magnet_token]), [
            'question' => 'Второй вопрос',
        ]);

        $this->assertSame('Первый вопрос', $enrollment->fresh()->day2_question);
    }

    public function test_complete_day_404s_for_unknown_token(): void
    {
        $this->post(route('marathon.day.complete', ['day' => 1, 'token' => 'nonexistent']))
            ->assertNotFound();
    }

    /** H445 Phase 3 — the `deva` cohort gets devanagari-recognition Day 1 content. */
    public function test_deva_cohort_day1_quiz_is_devanagari_content(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);
        MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id]);

        $this->get(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('Деванагари читается');
    }

    /** The `zero` cohort must keep its original cyrillic-only content unchanged. */
    public function test_zero_cohort_day1_quiz_is_unchanged(): void
    {
        [$lead] = $this->enrollment();

        $this->get(route('marathon.day', ['day' => 1, 'token' => $lead->magnet_token]))
            ->assertOk()
            ->assertDontSee('Деванагари читается');
    }
}
