<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H445 Phase 4 (H546) — the `deva` cohort's Day 2 page shows the mantra
 * reading task in place of the `zero` cohort's tap-choice grammar quiz
 * (MarathonController::day()), and the private voice-note download route
 * is admin-gated.
 */
class MarathonDay2MantraTest extends TestCase
{
    use RefreshDatabase;

    public function test_deva_cohort_sees_mantra_not_quiz(): void
    {
        $token = Str::random(16);
        $lead = Lead::factory()->create(['magnet_token' => $token]);
        MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id]);

        $response = $this->get(route('marathon.day', ['day' => 2, 'token' => $token]));

        $response->assertOk();
        $response->assertSee('apavitraḥ', false);
        $response->assertSee('puṇḍarīkākṣaṃ', false);
        $response->assertDontSee('gacchati');
    }

    public function test_zero_cohort_still_sees_grammar_quiz(): void
    {
        $token = Str::random(16);
        $lead = Lead::factory()->create(['magnet_token' => $token]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id]); // zero cohort

        $response = $this->get(route('marathon.day', ['day' => 2, 'token' => $token]));

        $response->assertOk();
        $response->assertDontSee('apavitraḥ', false);
    }

    public function test_deva_day2_complete_saves_question(): void
    {
        $token = Str::random(16);
        $lead = Lead::factory()->create(['magnet_token' => $token]);
        $enrollment = MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id]);

        $response = $this->post(route('marathon.day.complete', ['day' => 2, 'token' => $token]), [
            'question' => 'С чего начать читать деванагари?',
        ]);

        $response->assertRedirect(route('marathon.day', ['day' => 2, 'token' => $token]));
        $enrollment->refresh();
        $this->assertNotNull($enrollment->day2_engaged_at);
        $this->assertSame('С чего начать читать деванагари?', $enrollment->day2_question);
    }

    public function test_mantra_voice_download_route_requires_admin(): void
    {
        $enrollment = MarathonEnrollment::factory()->deva()->paid()->create([
            'day2_voice_disk' => 'local',
            'day2_voice_path' => 'marathon-mantra-voice/1.oga',
            'day2_voice_received_at' => now(),
        ]);

        $this->get(route('admin.marathon.mantra-voice.download', ['enrollment' => $enrollment->id]))
            ->assertRedirect(); // guest -> login redirect, never the file

        $student = User::factory()->create(['is_admin' => false]);
        $this->actingAs($student)
            ->get(route('admin.marathon.mantra-voice.download', ['enrollment' => $enrollment->id]))
            ->assertForbidden();
    }
}
