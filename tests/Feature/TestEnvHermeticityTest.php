<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H7632 — the test run must be hermetic to a machine-local `.env`.
 *
 * Measured 01-11-2026 on a clone carrying the canonical prod-shaped `.env`:
 * 162 failing tests across 85 classes (H7606 had already pinned its own
 * 8-filter cohort at 44/19). The fix is structural: `tests/CreatesApplication`
 * points the test run at the COMMITTED `.env.example` — the exact file CI has
 * always provisioned as `.env` — so every clone runs the same baseline. This
 * test is the regression pin: drop that line and the ops `.env` leaks again.
 */
class TestEnvHermeticityTest extends TestCase
{
    /** @test */
    public function the_test_run_reads_the_committed_example_env_not_a_machine_local_env(): void
    {
        $this->assertFileExists(
            base_path('.env.example'),
            '.env.example must stay committed: it is the hermetic baseline the '
            .'test run loads (H7632).'
        );

        $this->assertSame(
            '.env.example',
            basename(app()->environmentFilePath()),
            'The test run is reading '.app()->environmentFilePath()
            .' — a prod-shaped local .env leaks its flags into assertions (H7632).'
        );
    }

    /** @test */
    public function an_unset_feature_flag_keeps_its_config_default_in_the_test_run(): void
    {
        // A representative prod-shaped leak: the canonical .env sets
        // CRM_PIPELINE_BOARD=true while config/features.php defaults to false,
        // and this whole family of "flag default" tests asserts the default.
        $this->assertFalse(
            config('features.crm_pipeline_board'),
            'features.crm_pipeline_board must be at its config default (false) '
            .'during tests — a leaked .env flipped it (H7632).'
        );
    }
}
