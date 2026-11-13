<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H959 — kosha last-mile pipeline, Hop A (reader-as-a-service demo).
 * H965 — Hop C (difficulty-score advisory consumption), same route/flag.
 * /reading/kosha-demo renders the vendored Nala-1 reading pack, behind the
 * `features.kosha_reader` flag (default OFF).
 */
class ReadingPackTest extends TestCase
{
    public function test_flag_off_route_404s(): void
    {
        config(['features.kosha_reader' => false]);

        $this->get('/reading/kosha-demo')->assertNotFound();
    }

    public function test_flag_on_renders_pack_title_and_stats(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/kosha-demo')->assertOk();
        $res->assertSee('Nala 1', false);
        $res->assertSee('Mahābhārata', false);
        $res->assertSee('439', false); // stats.tokens
    }

    public function test_flag_on_renders_tokens_with_disclosed_gloss(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/kosha-demo')->assertOk();
        // First sentence's first token, form + lemma + gloss all present.
        $res->assertSee('bṛhadaśva', false);
        $res->assertSee('to speak', false); // vac's gloss (first sense)
    }

    public function test_flag_on_uses_native_details_disclosure_not_custom_js(): void
    {
        config(['features.kosha_reader' => true]);

        $html = $this->get('/reading/kosha-demo')->assertOk()->getContent();
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    public function test_flag_on_renders_own_difficulty_score_from_real_kosha_data(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/kosha-demo')->assertOk();
        // Real value from kosha's H949 scorer (data/difficulty/reading_pack_difficulty.json,
        // vendored verbatim) — not fabricated here.
        $res->assertSee('0.320', false); // nala-1's composite, number_format(...,3)
        $res->assertSee('Сложность текста', false);
    }

    public function test_flag_on_renders_ranked_list_with_current_pack_highlighted(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/kosha-demo')->assertOk();
        $res->assertSee('Nala 3', false); // a sibling scored pack, not vendored/readable itself
        $res->assertSee('Kirātārjunīya', false);
        $res->assertSee('эта страница', false);
    }

    public function test_sandhi_twin_toggle_toolbar_is_rendered(): void
    {
        config(['features.kosha_reader' => true]);

        $html = $this->get('/reading/kosha-demo')->assertOk()->getContent();
        // Initial state is server-rendered as «split», so the page is correct even
        // before the inline JS runs (and for a JS-off reader).
        $this->assertStringContainsString('data-sandhi-root data-sandhi-mode="split"', $html);
        $this->assertStringContainsString('data-sandhi-mode-btn="split"', $html);
        $this->assertStringContainsString('data-sandhi-mode-btn="text"', $html);
        $this->assertStringContainsString('В тексте (сандхи)', $html);
        $this->assertStringContainsString('data-reader-tokens', $html);
    }

    public function test_sandhi_twin_line_only_for_sentences_where_sandhi_changes_the_surface(): void
    {
        config(['features.kosha_reader' => true]);

        $html = $this->get('/reading/kosha-demo')->assertOk()->getContent();

        // Expected count is derived from the SAME vendored feed the page renders —
        // the twin exists exactly when the natural surface differs from the
        // space-join of the token forms (e.g. «vīrasenasuto» vs «vīrasena sutaḥ»).
        $pack = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/data/kosha_reading_pack_nala_1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expected = 0;
        foreach ($pack['sentences'] as $sentence) {
            $split = trim(implode(' ', array_map(static fn (array $t): string => (string) ($t['form'] ?? ''), $sentence['tokens'])));
            $surface = trim((string) ($sentence['text'] ?? ''));
            if ($split !== '' && $surface !== '' && $surface !== $split) {
                $expected++;
            }
        }

        $this->assertGreaterThan(0, $expected, 'fixture must contain at least one sandhi-divergent sentence');
        $this->assertSame($expected, substr_count($html, 'data-reader-sandhi'));
    }

    public function test_reader_onboarding_help_is_rendered_and_dismissible(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/kosha-demo')->assertOk();
        $res->assertSee('? Как пользоваться ридером', false);
        $res->assertSee('data-reader-help-panel', false);
        $res->assertSee('Два режима чтения', false);
        $res->assertSee('Пословно', false);
        $res->assertSee('data-reader-help-dismiss', false);
    }
}
