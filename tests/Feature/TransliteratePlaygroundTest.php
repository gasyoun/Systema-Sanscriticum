<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H1463 — Sanskrit-HUB L5 workstream A, v0. /transliterate renders the client-side
 * IAST→Devanāgarī+SLP1 playground behind features.hub_transliterate (default OFF).
 *
 * @vite is disabled in tests (TestCase::withoutVite()), so the surface is proven via
 * the stable DOM hooks the JS targets, and the "uses the vendored transcoder, not a
 * hand-rolled table" guarantee is proven by a Dusk-free static assertion on the JS
 * source (the transcoder itself is already proven against vectors upstream).
 */
class TransliteratePlaygroundTest extends TestCase
{
    public function test_flag_off_route_404s(): void
    {
        config(['features.hub_transliterate' => false]);

        $this->get('/transliterate')->assertNotFound();
    }

    public function test_flag_on_renders_playground_surface(): void
    {
        config(['features.hub_transliterate' => true]);

        $res = $this->get('/transliterate')->assertOk();
        // The input + the three result panes the JS wires live.
        $res->assertSee('id="translit-input"', false);
        $res->assertSee('id="translit-deva"', false);
        $res->assertSee('id="translit-iast"', false);
        $res->assertSee('id="translit-slp1"', false);
        $res->assertSee('data-playground="transliterate"', false);
        // At least one diacritic-insert button.
        $res->assertSee('data-diacritic="ā"', false);
    }

    public function test_flag_on_page_is_noindex(): void
    {
        config(['features.hub_transliterate' => true]);

        $this->get('/transliterate')->assertOk()->assertSee('noindex', false);
    }

    public function test_playground_uses_vendored_transcoder_not_a_hand_rolled_table(): void
    {
        $js = file_get_contents(base_path('resources/js/transliterate.js'));
        $this->assertIsString($js);

        // Imports the canonical vendored module and uses the abugida-correct path.
        $this->assertStringContainsString("from './vendor/sanskrit-util.js'", $js);
        $this->assertStringContainsString('to_slp1', $js);
        $this->assertStringContainsString('slp1_to_devanagari', $js);

        // Must NOT re-roll its own IAST→Devanāgarī table, and must NOT use the
        // display-only iast_to_devanagari (which renders "rāma" as "रआमअ").
        $this->assertStringNotContainsString('iast_to_devanagari', $js);
        $this->assertStringNotContainsString('अ', $js, 'transliterate.js must not embed a Devanāgarī table');
    }

    public function test_vendored_transcoder_file_exports_the_abugida_correct_functions(): void
    {
        $mod = file_get_contents(base_path('resources/js/vendor/sanskrit-util.js'));
        $this->assertIsString($mod);
        $this->assertStringContainsString('export function to_slp1', $mod);
        $this->assertStringContainsString('export function slp1_to_devanagari', $mod);
    }
}
