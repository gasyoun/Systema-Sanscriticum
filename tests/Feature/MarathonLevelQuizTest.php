<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H445 Phase 2 — level-quiz, `deva` cohort only.
 *
 * H1387 (20-07-2026): these tests used to hardcode `picks = [0,0,0,0,0,0]` as
 * the perfect run, because every item was authored with `correct` = 0 — which
 * was the defect, not a fixture convenience: a student who always tapped the
 * top option scored 6/6 and the grade carried no signal. The answers are now
 * spread, and the picks below are derived from config rather than written out,
 * so a future re-port cannot silently invalidate these fixtures.
 */
class MarathonLevelQuizTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Lead, 1: MarathonEnrollment} */
    private function devaEnrollment(): array
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);
        $enrollment = MarathonEnrollment::factory()->deva()->create(['lead_id' => $lead->id]);

        return [$lead, $enrollment];
    }

    /** @return list<array{text: string, opts: list<string>, correct: int}> */
    private function steps(): array
    {
        return config('marathon.cohorts.deva.level_quiz.steps');
    }

    /**
     * The picks that score 6/6, read off the config rather than assumed.
     *
     * @return list<int>
     */
    private function correctPicks(): array
    {
        return array_map(static fn (array $s): int => $s['correct'], $this->steps());
    }

    /**
     * The correct picks with the given items bumped to a neighbouring option,
     * so the expected score is 6 - count($wrongAt) whatever the order becomes.
     *
     * @param  list<int>  $wrongAt
     * @return list<int>
     */
    private function picksWithWrong(array $wrongAt): array
    {
        $picks = $this->correctPicks();
        $steps = $this->steps();
        foreach ($wrongAt as $i) {
            $picks[$i] = ($picks[$i] + 1) % count($steps[$i]['opts']);
        }

        return $picks;
    }

    public function test_level_quiz_answers_are_not_all_at_one_position(): void
    {
        $positions = $this->correctPicks();

        // The H1387 regression guard. If every answer sits at the same index
        // the quiz grades "always tap that option" as a perfect score, and
        // quiz_level stops measuring anything about the student.
        $this->assertGreaterThan(
            1,
            count(array_unique($positions)),
            'All level-quiz answers share one option position — the quiz is solvable without reading it.',
        );
    }

    public function test_level_quiz_every_answer_index_is_in_range(): void
    {
        foreach ($this->steps() as $i => $step) {
            $this->assertArrayHasKey(
                $step['correct'],
                $step['opts'],
                "Item {$i} points at option {$step['correct']}, which does not exist.",
            );
        }
    }

    public function test_level_quiz_page_renders_for_deva_cohort(): void
    {
        [$lead] = $this->devaEnrollment();

        $this->get(route('marathon.level-quiz', ['token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('уровень санскрита');
    }

    public function test_level_quiz_404s_for_zero_cohort(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);

        $this->get(route('marathon.level-quiz', ['token' => $lead->magnet_token]))
            ->assertNotFound();
    }

    public function test_level_quiz_404s_for_unknown_token(): void
    {
        $this->get(route('marathon.level-quiz', ['token' => 'nonexistent']))
            ->assertNotFound();
    }

    public function test_complete_scores_perfect_run_server_side(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();

        $this->post(route('marathon.level-quiz.complete', ['token' => $lead->magnet_token]), [
            'picks' => $this->correctPicks(),
        ])->assertRedirect(route('marathon.level-quiz.result', ['token' => $lead->magnet_token]));

        $this->assertSame(6, $enrollment->fresh()->quiz_level);
    }

    public function test_complete_scores_partial_run(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();

        $this->post(route('marathon.level-quiz.complete', ['token' => $lead->magnet_token]), [
            'picks' => $this->picksWithWrong([1, 2, 4]),
        ]);

        $this->assertSame(3, $enrollment->fresh()->quiz_level);
    }

    public function test_complete_ignores_client_reported_score_uses_server_grading(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();

        // A tampered/incomplete submission (all wrong picks) must still be
        // graded server-side against config('marathon.cohorts.deva.level_quiz'),
        // never trusting a client-supplied score field.
        $this->post(route('marathon.level-quiz.complete', ['token' => $lead->magnet_token]), [
            'picks' => [9, 9, 9, 9, 9, 9],
            'quiz_level' => 6,
        ]);

        $this->assertSame(0, $enrollment->fresh()->quiz_level);
    }

    public function test_complete_is_idempotent(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();
        $enrollment->update(['quiz_level' => 4]);

        $this->post(route('marathon.level-quiz.complete', ['token' => $lead->magnet_token]), [
            'picks' => $this->correctPicks(),
        ]);

        $this->assertSame(4, $enrollment->fresh()->quiz_level);
    }

    public function test_complete_404s_for_zero_cohort(): void
    {
        $lead = Lead::factory()->create(['magnet_token' => Str::random(12)]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id]);

        $this->post(route('marathon.level-quiz.complete', ['token' => $lead->magnet_token]), [
            'picks' => $this->correctPicks(),
        ])->assertNotFound();
    }

    public function test_already_taken_redirects_straight_to_result(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();
        $enrollment->update(['quiz_level' => 5]);

        $this->get(route('marathon.level-quiz', ['token' => $lead->magnet_token]))
            ->assertRedirect(route('marathon.level-quiz.result', ['token' => $lead->magnet_token]));
    }

    public function test_result_page_shows_score(): void
    {
        [$lead, $enrollment] = $this->devaEnrollment();
        $enrollment->update(['quiz_level' => 4]);

        $this->get(route('marathon.level-quiz.result', ['token' => $lead->magnet_token]))
            ->assertOk()
            ->assertSee('4 из 6');
    }

    public function test_result_page_404s_before_quiz_taken(): void
    {
        [$lead] = $this->devaEnrollment();

        $this->get(route('marathon.level-quiz.result', ['token' => $lead->magnet_token]))
            ->assertNotFound();
    }
}
