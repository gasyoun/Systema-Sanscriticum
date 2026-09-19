<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Exports\CourseStreamComparisonExport;
use App\Models\Lead;
use App\Models\User;
use App\Support\FormulaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5086 regression · csv-export-formula-injection.
 *
 * Same anonymous payload store as the NV-08 proof, assertions flipped to the
 * fixed invariant: no export cell may START with a spreadsheet-significant
 * character (=, +, -, @) — the neutralizer prefixes such strings with a
 * single quote at all four export writers.
 */
class H5086CsvExportFormulaNeutralizedTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '=HYPERLINK("http://127.0.0.1:9/x","p")';

    /** @test */
    public function anonymous_lead_payload_is_neutralized_in_csv_export_cells(): void
    {
        // 1) Anonymous store of the crafted lead (the unauthenticated entrypoint).
        $this->post('/leads/store', [
            'contact' => '+7 999 000 00 00',
            'name' => 'Гость НВ08',
            'is_promo_agreed' => '1',
            'utm_source' => self::PAYLOAD,
            'utm_campaign' => '+SUM(1+1)*cmd|/C calc!A0',
            'utm_term' => '-2+3+cmd|/C calc!A0',
            'utm_content' => '@SUM(A1:A9)',
        ])->assertRedirect();

        $lead = Lead::where('utm_source', self::PAYLOAD)->first();
        $this->assertNotNull($lead, 'crafted lead stored from the anonymous form');

        // 2) Staff export.
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get('/admin/leads/export');

        $response->assertOk();
        $csv = $response->streamedContent();

        // Decisive: decode the actual CSV and compare CELL VALUES.
        $lines = array_values(array_filter(explode("\n", $csv)));
        $dataLine = collect($lines)->first(fn ($l) => str_contains($l, 'HYPERLINK'));
        $this->assertNotNull($dataLine, '\'=\'-cell present in export');
        $cells = str_getcsv($dataLine, ';');

        // Neutralized values: leading apostrophe, payload preserved verbatim after it.
        $this->assertContains('\''.self::PAYLOAD, $cells, 'equals-cell is apostrophe-prefixed');
        $this->assertContains('\'+SUM(1+1)*cmd|/C calc!A0', $cells, '\'+\'-cell is apostrophe-prefixed');
        $this->assertContains('\'-2+3+cmd|/C calc!A0', $cells, '\'-\'-cell is apostrophe-prefixed');
        $this->assertContains('\'@SUM(A1:A9)', $cells, '\'@\'-cell is apostrophe-prefixed');

        // And NO cell starts with a formula-significant character anymore.
        foreach ($cells as $cell) {
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell,
                'no export cell may start with a spreadsheet formula character');
        }
    }

    /** @test */
    public function control_non_admin_is_denied_export(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/leads/export')->assertForbidden();
    }

    /** @test */
    public function formula_guard_covers_every_dangerous_prefix_and_passes_data_through(): void
    {
        $this->assertSame('\'=1+1', FormulaGuard::cell('=1+1'));
        $this->assertSame('\'+CMD', FormulaGuard::cell('+CMD'));
        $this->assertSame('\'-CMD', FormulaGuard::cell('-CMD'));
        $this->assertSame('\'@CMD', FormulaGuard::cell('@CMD'));
        $this->assertSame("'\tCMD", FormulaGuard::cell("\tCMD"));
        $this->assertSame("'\rCMD", FormulaGuard::cell("\rCMD"));

        // Data that is not formula-shaped passes through untouched.
        $this->assertSame('обычная строка', FormulaGuard::cell('обычная строка'));
        $this->assertSame('2026-09-17 12:00', FormulaGuard::cell('2026-09-17 12:00'));
        $this->assertSame(42, FormulaGuard::cell(42));
        $this->assertSame(-12.5, FormulaGuard::cell(-12.5));
        $this->assertNull(FormulaGuard::cell(null));
        $this->assertSame('', FormulaGuard::cell(''));

        $this->assertSame(
            ["'=A1", 'ok', 7, null],
            FormulaGuard::row(['=A1', 'ok', 7, null])
        );
    }

    /** @test */
    public function xlsx_stream_comparison_neutralizes_guest_student_names(): void
    {
        $report = [
            'family' => 'family-x',
            'family_title' => 'Семья X',
            'streams' => [
                [
                    'course_id' => 1,
                    'title' => 'Поток 1',
                    'role' => 'live',
                    'payers' => 1,
                    'revenue' => 100,
                    'avg_check' => 100,
                    'discount_total' => 0,
                    'accrued' => 100,
                    'retention_first_to_last' => 100,
                    'blocks' => [
                        ['number' => 1, 'buyers' => 1, 'access' => 1, 'revenue' => 100],
                    ],
                    'students' => [
                        ['id' => 11, 'name' => '=HYPERLINK("http://127.0.0.1:9/x","p")', 'blocks' => [1 => true]],
                    ],
                ],
            ],
            'attendance' => [
                'covered_users' => 1,
                'total_users' => 1,
                'coverage_ratio' => 1.0,
                'bought_all_never_watched' => [],
            ],
            'salary' => [
                'attribution_confirmed' => true,
                'remainder' => 0.0,
            ],
        ];

        $rows = (new CourseStreamComparisonExport($report))->array();

        $studentRow = collect($rows)->first(fn (array $r) => count($r) >= 2 && ($r[1] ?? null) === 11);
        $this->assertNotNull($studentRow, 'student row present in xlsx array');

        $this->assertSame("'=HYPERLINK(\"http://127.0.0.1:9/x\",\"p\")", $studentRow[0],
            'guest student name cell must not start with the formula character');
    }
}
