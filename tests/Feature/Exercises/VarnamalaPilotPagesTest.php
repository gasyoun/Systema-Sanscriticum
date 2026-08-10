<?php

declare(strict_types=1);

namespace Tests\Feature\Exercises;

use Tests\TestCase;

/**
 * H2436 — Varnamālā pilot static pack (Metamorphabet-inspired akṣara toy).
 */
class VarnamalaPilotPagesTest extends TestCase
{
    /** @test */
    public function pilot_shell_and_data_exist(): void
    {
        $this->assertFileExists(public_path('lila/varnamala/data.js'));
        $this->assertFileExists(public_path('lila/varnamala/engine.js'));
        $this->assertFileExists(public_path('lila/varnamala/engine.css'));
        $this->assertFileExists(public_path('lila/varnamala/index.html'));
        $this->assertFileExists(public_path('lila/varnamala/pilot/index.html'));
        $this->assertFileExists(base_path('docs/VARNAMALA_PILOT_BRIEF_2026.md'));
    }

    /** @test */
    public function pilot_page_wires_gate_telemetry_and_locale(): void
    {
        $html = file_get_contents(public_path('lila/varnamala/pilot/index.html'));

        $this->assertStringContainsString('/lila/gate.js', $html);
        $this->assertStringContainsString(
            'data-drill="varnamala" data-band="pilot"',
            $html
        );
        $this->assertStringContainsString('/lila/locale.js', $html);
        $this->assertStringContainsString('../data.js', $html);
        $this->assertStringContainsString('../engine.js', $html);
        $this->assertStringContainsString('VarnamalaPilot.mount', $html);
    }

    /** @test */
    public function pilot_page_wires_rive_runtime_and_bridge(): void
    {
        $html = file_get_contents(public_path('lila/varnamala/pilot/index.html'));

        $this->assertStringContainsString('@rive-app/canvas', $html);
        $this->assertStringContainsString('../rive-bridge.js', $html);
        $this->assertFileExists(public_path('lila/varnamala/rive-bridge.js'));
        $this->assertFileExists(public_path('lila/varnamala/rive/README.md'));

        $bridge = file_get_contents(public_path('lila/varnamala/rive-bridge.js'));
        $this->assertStringContainsString('VarnamalaRive', $bridge);
        $this->assertStringContainsString('state_machine', $bridge);

        $data = file_get_contents(public_path('lila/varnamala/data.js'));
        $this->assertStringContainsString('state_machine: "Varnamala"', $data);
        $this->assertStringContainsString('stage_input: "stage"', $data);
        $this->assertStringContainsString('w1_keys: ["ka", "ma"]', $data);
    }

    /** @test */
    public function data_fixture_has_ten_aksaras_and_complete_threshold(): void
    {
        $js = file_get_contents(public_path('lila/varnamala/data.js'));

        $this->assertStringContainsString('VARNAMALA_PILOT', $js);
        $this->assertStringContainsString('complete_distinct_for_play: 5', $js);
        foreach (['अ', 'आ', 'इ', 'उ', 'क', 'ग', 'च', 'त', 'न', 'म'] as $deva) {
            $this->assertStringContainsString('devanagari: "'.$deva.'"', $js);
        }
        $this->assertStringContainsString('कमल', $js);
        $this->assertStringContainsString('मयूर', $js);
    }

    /** @test */
    public function catalogue_links_the_pilot(): void
    {
        $lila = file_get_contents(public_path('lila/index.html'));
        $this->assertStringContainsString('href="varnamala/pilot/"', $lila);

        $family = file_get_contents(public_path('lila/varnamala/index.html'));
        $this->assertStringContainsString('href="pilot/"', $family);
    }
}
