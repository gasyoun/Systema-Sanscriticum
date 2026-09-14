<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4818 (R2609-01) — F2 rung-placement quiz routes. Offline, fixture-pinned:
 * no DB fixtures needed, the quiz tree comes entirely from
 * config/placement_quiz.php.
 */
class PlacementQuizTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flag_defaults_off(): void
    {
        $this->assertFalse(config('features.f2_placement_quiz'));
    }

    /** @test */
    public function show_is_404_when_flag_off(): void
    {
        config(['features.f2_placement_quiz' => false]);

        $this->get(route('placement.quiz.show'))->assertNotFound();
    }

    /** @test */
    public function store_is_404_when_flag_off(): void
    {
        config(['features.f2_placement_quiz' => false]);

        $this->postJson(route('placement.quiz.store'), ['rung' => 'B1'])->assertNotFound();
    }

    /** @test */
    public function show_renders_the_quiz_tree_when_flag_on(): void
    {
        config(['features.f2_placement_quiz' => true]);

        $this->get(route('placement.quiz.show'))
            ->assertOk()
            ->assertSee('Насколько вы знакомы с деванагари и санскритом?');
    }

    /** @test */
    public function store_rejects_a_rung_outside_the_pedagogy_vocabulary(): void
    {
        config(['features.f2_placement_quiz' => true]);

        $this->postJson(route('placement.quiz.store'), ['rung' => 'Z9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rung');
    }

    /** @test */
    public function store_rejects_a_missing_rung(): void
    {
        config(['features.f2_placement_quiz' => true]);

        $this->postJson(route('placement.quiz.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rung');
    }

    /** @test */
    public function store_persists_a_valid_rung_to_session(): void
    {
        config(['features.f2_placement_quiz' => true]);

        $this->postJson(route('placement.quiz.store'), ['rung' => 'B1'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('B1', session('placement_rung'));
    }

    /** @test */
    public function every_leaf_of_every_branch_resolves_to_one_of_the_seven_rungs(): void
    {
        // Walks the config tree exhaustively — a broken `next` reference (typo,
        // orphaned node) would 404/mis-render client-side without this check.
        $quiz = config('placement_quiz');
        $rungCodes = array_keys($quiz['results']);
        $this->assertSame(['A0', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'], $rungCodes);

        $seen = [];
        $walk = function (string $step) use (&$walk, &$seen, $quiz): void {
            $this->assertArrayHasKey($step, $quiz['questions'], "Dangling question ref: {$step}");
            foreach ($quiz['questions'][$step]['opts'] as $opt) {
                $next = $opt['next'];
                if (array_key_exists($next, $quiz['results'])) {
                    $seen[$next] = true;
                } else {
                    $walk($next);
                }
            }
        };
        $walk($quiz['first']);

        $this->assertSame(['A0', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'], array_keys($seen), 'every rung must be reachable from the tree root');
    }
}
