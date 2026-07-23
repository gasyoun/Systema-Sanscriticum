<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H1463 — /transliterate flag gate + playground markup.
 * Executed by Grok 4.5 (grok-4.5) via xAI — Claude/Opus verify after.
 */
class TransliteratePlaygroundTest extends TestCase
{
    public function test_flag_off_route_404s(): void
    {
        config(['features.hub_transliterate' => false]);

        $this->get('/transliterate')->assertNotFound();
    }

    public function test_flag_on_renders_playground_and_vite_handle(): void
    {
        config(['features.hub_transliterate' => true]);

        $res = $this->get('/transliterate')->assertOk();
        $res->assertSee('hub-transliterate-input', false);
        $res->assertSee('hub-result-deva', false);
        $res->assertSee('hub-result-iast', false);
        $res->assertSee('hub-result-slp1', false);
        $res->assertSee('hub-diacritics', false);
        // @vite is stripped by TestCase::withoutVite(); assert playground
        // wiring + copy instead of the asset filename.
        $res->assertSee('Транслитерация', false);
        $res->assertSee('sanskrit-util', false);
    }
}
