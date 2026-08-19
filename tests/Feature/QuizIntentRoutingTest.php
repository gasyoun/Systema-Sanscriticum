<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G31 / H3190 — the «С чего начать» intent quiz routes every option somewhere real.
 *
 * The quiz (ShopController::start) is the top-of-funnel router for the 28-08-2026
 * cohort-zero launch: a newcomer answers two questions and is handed a course. It
 * shipped with no test at all — G31 has carried «author it if absent» since
 * 10-07-2026 (create-not-gate), and three composition passes re-confirmed it absent.
 *
 * What is pinned here is the map's SHAPE, derived from the rendered structure rather
 * than written out, in the spirit of MarathonLevelQuizTest's H1387 note: a fixture
 * that repeats the map by hand cannot catch the map changing. So no test below names
 * an option label or a branch key it did not read from the page first — a re-port,
 * a new branch, or a renamed result key is caught rather than silently re-blessed.
 *
 * The load-bearing claim is the controller's own docblock: «битых ссылок не бывает»
 * — a course is matched by LIKE pattern (config/onramp.php) and, when nothing
 * matches, the CTA falls back to a filtered catalogue. Both halves are asserted,
 * because the fallback is what a fresh cohort with rotated slugs actually hits.
 */
class QuizIntentRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{questions: array<string, mixed>, results: array<string, mixed>, first: string} */
    private function quiz(): array
    {
        $response = $this->get(route('shop.start'));
        $response->assertOk();

        return $response->viewData('quiz');
    }

    public function test_the_entry_question_exists_in_the_question_set(): void
    {
        $quiz = $this->quiz();

        $this->assertArrayHasKey(
            $quiz['first'],
            $quiz['questions'],
            "The quiz opens on '{$quiz['first']}', which is not a declared question."
        );
    }

    public function test_every_option_routes_to_a_declared_question_or_result(): void
    {
        $quiz = $this->quiz();
        $targets = array_merge(array_keys($quiz['questions']), array_keys($quiz['results']));

        $checked = 0;
        foreach ($quiz['questions'] as $key => $question) {
            $this->assertNotEmpty($question['opts'], "Question '{$key}' offers no options.");

            foreach ($question['opts'] as $opt) {
                $this->assertContains(
                    $opt['next'],
                    $targets,
                    "Option «{$opt['label']}» of '{$key}' routes to '{$opt['next']}', "
                    .'which is neither a question nor a result — a dead end for a real student.'
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'The quiz declared no options at all.');
    }

    public function test_every_result_is_reachable_from_the_entry_question(): void
    {
        $quiz = $this->quiz();

        $seen = [];
        $queue = [$quiz['first']];
        while ($queue !== []) {
            $key = array_shift($queue);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            foreach ($quiz['questions'][$key]['opts'] ?? [] as $opt) {
                $queue[] = $opt['next'];
            }
        }

        foreach (array_keys($quiz['results']) as $result) {
            $this->assertArrayHasKey(
                $result,
                $seen,
                "Result '{$result}' is authored but no sequence of answers reaches it."
            );
        }
    }

    public function test_every_result_offers_at_least_one_usable_cta(): void
    {
        $quiz = $this->quiz();

        foreach ($quiz['results'] as $key => $result) {
            $this->assertNotEmpty($result['ctas'], "Result '{$key}' hands the student no CTA.");

            foreach ($result['ctas'] as $cta) {
                $this->assertNotSame('', trim((string) $cta['url']),
                    "Result '{$key}' has a CTA «{$cta['label']}» with an empty URL.");
                $this->assertMatchesRegularExpression(
                    '#^(https?://|/)#',
                    $cta['url'],
                    "Result '{$key}' CTA «{$cta['label']}» is not a resolvable link: {$cta['url']}"
                );
            }

            $primary = array_filter($result['ctas'], static fn (array $c): bool => (bool) ($c['primary'] ?? false));
            $this->assertCount(1, $primary, "Result '{$key}' must offer exactly one primary CTA.");
        }
    }

    public function test_a_matching_course_becomes_the_primary_cta_of_its_branch(): void
    {
        $patterns = config('onramp.recommendations');
        $this->assertNotEmpty($patterns, 'config/onramp.php declares no recommendation patterns.');

        $expected = [];
        foreach ($patterns as $branch => $pattern) {
            $course = Course::factory()->create([
                'title' => $pattern.' — курс для теста маршрутизации',
                'is_visible' => true,
            ]);
            $expected[$branch] = route('shop.course.show', $course->slug);
        }

        $quiz = $this->quiz();

        foreach ($expected as $branch => $url) {
            $this->assertArrayHasKey($branch, $quiz['results'],
                "config/onramp.php recommends for '{$branch}', but the quiz has no such result.");

            $primary = $this->primaryUrl($quiz['results'][$branch]);
            $this->assertSame($url, $primary,
                "Branch '{$branch}' matched a course by pattern «{$patterns[$branch]}» "
                .'but its primary CTA does not point at that course.');
        }
    }

    public function test_a_branch_with_no_matching_course_falls_back_instead_of_dead_ending(): void
    {
        // No courses at all: every pattern misses. This is the state a fresh cohort
        // hits whenever slugs rotate between streams, so it is the normal case, not
        // the edge one.
        $quiz = $this->quiz();

        foreach (array_keys(config('onramp.recommendations')) as $branch) {
            $primary = $this->primaryUrl($quiz['results'][$branch]);

            $this->assertNotSame('', trim($primary),
                "Branch '{$branch}' has no course and no fallback — the CTA is empty.");
            $this->assertMatchesRegularExpression('#^(https?://|/)#', $primary,
                "Branch '{$branch}' fell back to something that is not a link: {$primary}");
            // Redirects are followed on purpose: a fallback into the catalogue may
            // canonicalise a legacy query param (?level=) into its pretty path, and a
            // 301 on the way to a real page is not a broken link.
            $this->followingRedirects()->get($primary)->assertSuccessful();
        }
    }

    public function test_a_hidden_course_is_never_recommended(): void
    {
        // The branch is read off config rather than assumed: if the grammar branch is
        // ever renamed, this test says so instead of quietly testing nothing.
        $branch = array_key_first(config('onramp.recommendations'));
        $pattern = config("onramp.recommendations.{$branch}");

        $hidden = Course::factory()->hidden()->create([
            'title' => $pattern.' — скрытый курс',
        ]);

        $quiz = $this->quiz();
        $this->assertArrayHasKey($branch, $quiz['results']);
        $primary = $this->primaryUrl($quiz['results'][$branch]);

        $this->assertNotSame(
            route('shop.course.show', $hidden->slug),
            $primary,
            'A hidden course was offered to a newcomer as the recommended next step.'
        );
    }

    /** @param array{ctas: list<array{url: string, primary?: bool}>} $result */
    private function primaryUrl(array $result): string
    {
        foreach ($result['ctas'] as $cta) {
            if ($cta['primary'] ?? false) {
                return (string) $cta['url'];
            }
        }

        return '';
    }
}
