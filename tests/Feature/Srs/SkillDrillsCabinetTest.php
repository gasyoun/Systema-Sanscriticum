<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1680 — Wave 2: cabinet skill-drill strip (/dvaram/skill-drills), DISTINCT
 * from the FSRS review loop (/dvaram/srs) and gated by its own flag
 * (features.games_skill_drills), OFF by default (Architecture §5). The
 * flag-ON half lives in SkillDrillsCabinetFlagOnTest — routes are
 * registered at boot from config, so that half must set the flag via env
 * BEFORE the app boots (mirrors SrsFlagOverrideTest).
 */
class SkillDrillsCabinetTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function route_is_hidden_by_default(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dvaram/skill-drills')->assertNotFound();
    }
}
