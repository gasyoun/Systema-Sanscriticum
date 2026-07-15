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
}
