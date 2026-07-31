<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarathonLandingCopyVariantEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2010 — the report command MG's ruling asks for: raw counts + rate per
 * variant, no auto-declared winner.
 */
class MarathonCopyVariantReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_computes_conversion_rate_per_variant(): void
    {
        MarathonLandingCopyVariantEvent::insert([
            ['variant' => 'a', 'event_type' => 'impression', 'created_at' => now()],
            ['variant' => 'a', 'event_type' => 'impression', 'created_at' => now()],
            ['variant' => 'a', 'event_type' => 'lead', 'created_at' => now()],
            ['variant' => 'b', 'event_type' => 'impression', 'created_at' => now()],
        ]);

        $this->artisan('marathon:copy-variant-report')
            ->expectsTable(
                ['Variant', 'Impressions', 'Leads', 'Conversion'],
                [
                    ['A', 2, 1, '50%'],
                    ['B', 1, 0, '0%'],
                ]
            )
            ->assertSuccessful();
    }

    public function test_report_handles_zero_impressions_without_division_error(): void
    {
        $this->artisan('marathon:copy-variant-report')
            ->expectsTable(
                ['Variant', 'Impressions', 'Leads', 'Conversion'],
                [
                    ['A', 0, 0, 'n/a'],
                    ['B', 0, 0, 'n/a'],
                ]
            )
            ->assertSuccessful();
    }
}
