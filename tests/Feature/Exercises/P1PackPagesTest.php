<?php

declare(strict_types=1);

namespace Tests\Feature\Exercises;

use Tests\TestCase;

class P1PackPagesTest extends TestCase
{
    /** @test */
    public function the_three_p1_packs_exist(): void
    {
        $this->assertFileExists(public_path('lila/roots/ru-faces/index.html'));
        $this->assertFileExists(public_path('lila/ligatures/top-10/index.html'));
        $this->assertFileExists(public_path('lila/cloze/root-rank/index.html'));
    }

    /** @test */
    public function every_p1_pack_references_the_free_games_gate_and_telemetry(): void
    {
        $pages = [
            'lila/roots/ru-faces/index.html' => ['roots', 'ru-faces'],
            'lila/ligatures/top-10/index.html' => ['ligatures', 'top-10'],
            'lila/cloze/root-rank/index.html' => ['cloze', 'root-rank'],
        ];

        foreach ($pages as $path => [$drill, $band]) {
            $html = file_get_contents(public_path($path));
            $this->assertStringContainsString('/lila/gate.js', $html, "{$path} must load the free-games gate");
            $this->assertStringContainsString(
                'data-drill="'.$drill.'" data-band="'.$band.'"',
                $html,
                "{$path} must carry the expected telemetry drill/band ids"
            );
            $this->assertStringContainsString('/lila/locale.js', $html, "{$path} must load the RU/EN locale shell");
        }
    }

    /** @test */
    public function roots_ru_faces_reuses_the_generated_root_data_without_devanagari_faces(): void
    {
        $html = file_get_contents(public_path('lila/roots/ru-faces/index.html'));

        $this->assertStringContainsString('../data.js', $html);
        $this->assertStringContainsString('ROOT_BANDS.top25', $html);
        $this->assertStringContainsString('row.gloss_ru', $html);
        // The RU-primary face must not build its left/right cards from
        // row.devanagari -- the whole point of this pack is no Devanagari
        // requirement on either face.
        $this->assertStringNotContainsString('row.devanagari', $html);
    }

    /** @test */
    public function ligatures_top10_carries_a_cyrillic_hint_alongside_iast(): void
    {
        $html = file_get_contents(public_path('lila/ligatures/top-10/index.html'));

        $this->assertStringContainsString('CYRILLIC_HINT', $html);
        $this->assertStringContainsString('LIGATURE_BANDS.top10', $html);
    }

    /** @test */
    public function cloze_root_rank_covers_all_25_roots_with_no_hand_authored_bands(): void
    {
        $html = file_get_contents(public_path('lila/cloze/root-rank/index.html'));

        $this->assertStringContainsString('ROOT_BANDS.top25', $html);
        $this->assertStringContainsString('BAND_SIZE', $html);
        // Bands are computed from ROOT_BANDS at runtime, not hand-authored --
        // there should be no hard-coded 25-entry segments literal in the page.
        $this->assertStringNotContainsString('segments: [', $html);
    }

    /** @test */
    public function catalogue_pages_link_to_the_new_p1_packs(): void
    {
        $rootsIndex = file_get_contents(public_path('lila/roots/index.html'));
        $this->assertStringContainsString('href="ru-faces/"', $rootsIndex);

        $clozeIndex = file_get_contents(public_path('lila/cloze/index.html'));
        $this->assertStringContainsString('href="root-rank/"', $clozeIndex);
    }
}
