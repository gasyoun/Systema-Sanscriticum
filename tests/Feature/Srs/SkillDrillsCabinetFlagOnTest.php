<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1680 — flag-ON half of the cabinet skill-drill strip contract. Routes
 * are registered at boot from config('features.games_skill_drills'), so the
 * override must be set via env vars BEFORE the app boots (same technique as
 * SrsFlagOverrideTest) — a runtime config() call in the test body is too late.
 */
class SkillDrillsCabinetFlagOnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('GAMES_SKILL_DRILLS=true');
        $_ENV['GAMES_SKILL_DRILLS'] = 'true';
        $_SERVER['GAMES_SKILL_DRILLS'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('GAMES_SKILL_DRILLS');
        unset($_ENV['GAMES_SKILL_DRILLS'], $_SERVER['GAMES_SKILL_DRILLS']);

        parent::tearDown();
    }

    /** @test */
    public function route_is_reachable_when_flag_on_and_distinct_from_srs_review(): void
    {
        $this->assertTrue(config('features.games_skill_drills'));
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dvaram/skill-drills');
        $response->assertOk();
        $response->assertSee('/lila/', false);

        $this->assertSame('/dvaram/skill-drills', parse_url(route('student.skill-drills'), PHP_URL_PATH));
        $this->assertNotSame('/dvaram/srs', parse_url(route('student.skill-drills'), PHP_URL_PATH));
    }

    /** @test */
    public function guest_is_redirected(): void
    {
        $this->get('/dvaram/skill-drills')->assertRedirect();
    }
}
