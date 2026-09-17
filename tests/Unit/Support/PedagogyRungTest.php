<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PedagogyRung;
use Tests\TestCase;

/**
 * H4818 (R2609-01) — mirrors docs/schema/ai_native_pedagogy_model.yaml
 * `vocabularies.rung`. If that YAML's rung list ever changes, this test
 * documents the PHP mirror it must be kept in sync with by hand.
 */
class PedagogyRungTest extends TestCase
{
    public function test_values_match_the_seven_ai_native_pedagogy_model_rungs(): void
    {
        $this->assertSame(
            ['A0', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'],
            PedagogyRung::values()
        );
    }

    public function test_from_accepts_a_known_rung_code(): void
    {
        $this->assertSame(PedagogyRung::B1, PedagogyRung::from('B1'));
    }

    public function test_tryfrom_returns_null_for_an_unknown_code(): void
    {
        $this->assertNull(PedagogyRung::tryFrom('Z9'));
    }
}
