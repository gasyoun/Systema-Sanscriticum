<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1145 — pins the R-6 default: `srs.enabled` must be OFF unless a prod
 * `.env` sets `SRS_ENABLED=true` explicitly. H447 (PR #442, commit 6267d70)
 * flipped the default to true for the August-2026 pilot; that call was
 * reasonable when made but does not survive R-5/R-6 — an SRS nav entry
 * appearing for every student without an explicit opt-in corrupts the R20
 * baseline. This test does NOT set `SRS_ENABLED` anywhere, so it exercises
 * the real config default, not an overridden one.
 */
class SrsFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_srs_enabled_defaults_to_false(): void
    {
        $this->assertFalse(config('srs.enabled'));
    }

    public function test_srs_review_route_is_404_by_default(): void
    {
        // The route registration itself is gated behind `if (config('srs.enabled'))`
        // in routes/web.php, so with the flag OFF the URI has no route at all —
        // this is the student-visible surface R-6 protects, checked independently
        // of the config assertion above.
        $this->get('/dvaram/srs')->assertNotFound();
    }
}
