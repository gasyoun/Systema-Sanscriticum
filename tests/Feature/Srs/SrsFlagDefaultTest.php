<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product default (30-07-2026): `srs.enabled` is ON unless `.env` sets
 * `SRS_ENABLED=false`. R-6 had pinned OFF-by-default to protect an R20
 * cabinet baseline; that gate is lifted now that per-deck public trial URLs
 * and the review surface are intentional product features.
 *
 * This test does NOT set `SRS_ENABLED` in the environment, so it exercises
 * the real config default, not an overridden one.
 */
class SrsFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_srs_enabled_defaults_to_true(): void
    {
        $this->assertTrue(config('srs.enabled'));
    }

    public function test_srs_routes_registered_by_default(): void
    {
        $this->assertTrue(app('router')->has('student.srs'));
        $this->assertTrue(app('router')->has('student.srs.deck'));
        $this->assertTrue(app('router')->has('srs.index'));
        $this->assertTrue(app('router')->has('srs.deck'));
    }
}
