<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * H5093 · Shared trust-boundary contract assertions.
 *
 * Preventive contract derived from the H5046 failure classes remediated in
 * PR #2670 (csv-export-formula-injection), PR #2672
 * (lead-duplicate-flash-magnet-token-disclosure) and PR #2675
 * (payment-hard-delete-skips-access-revocation).
 *
 * Any NEW surface that moves (a) guest-controlled strings into spreadsheet
 * cells, (b) capability-bearing links into guest-visible responses, or
 * (c) destructive money/access transitions through user-reachable code paths
 * must declare its five contract fields (source / sink / authority /
 * invariant / negative regression) in
 * docs/TRUST_BOUNDARY_CONTRACT_MATRIX.md and reuse these assertions so a
 * planted violation turns the suite red.
 *
 * @see docs/TRUST_BOUNDARY_CONTRACT_MATRIX.md
 */
trait AssertsTrustBoundaries
{
    /**
     * Spreadsheet-significant first characters (OWASP CSV-injection classes
     * neutralized by App\Support\FormulaGuard at the export writers).
     */
    public const TRUST_FORMULA_PREFIXES = ['=', '+', '@', "\t", "\r"];

    /**
     * Session keys that carry bearer capability links. If any of these reach
     * an anonymous/guest-visible flash, the bearer value is disclosed.
     */
    public const TRUST_CAPABILITY_KEYS = [
        'duplicate_deep_link',
        'duplicate_channel',
        'status_connect_links',
        'magnet_deep_links',
        'marathon_telegram_link',
    ];

    /**
     * TRUE when a serialized cell value would be evaluated as a formula by
     * Excel/LibreOffice. Only FULLY NUMERIC negatives ("-12.5", "-42",
     * "-1.2e3") are safe — Excel parses those as numbers; anything else
     * starting with "-" ("-cmd|...", "-1+HYPERLINK(...)") is unary-minus
     * formula injection. Matches FormulaGuard, which neutralizes every
     * dangerous-shaped string cell with a leading apostrophe (any leading
     * "-" string counts as dangerous) and passes real numbers through.
     */
    public function trustBoundaryFormulaShapedCell(?string $cell): bool
    {
        if ($cell === null || $cell === '') {
            return false;
        }

        $first = $cell[0];

        if (in_array($first, self::TRUST_FORMULA_PREFIXES, true)) {
            return true;
        }

        if ($first === '-') {
            $rest = substr($cell, 1);

            return preg_match('/^\d+(\.\d+)?([eE][+-]?\d+)?$/', $rest) !== 1;
        }

        return false;
    }

    /**
     * Boundary A invariant: no cell of a staff-facing spreadsheet export may
     * start with a formula-significant character. Decodes the real CSV stream
     * row by row via fgetcsv (RFC4180-correct: quoted embedded newlines stay
     * inside their cell instead of producing phantom fragments), so every
     * column of every row is covered.
     */
    public function assertCsvCellsFormulaNeutral(string $csv, string $delimiter = ';', string $message = 'trust-boundary A: export cells must be formula-neutral'): void
    {
        $handle = fopen('php://temp', 'r+');
        self::assertNotFalse($handle, $message.' — could not open decode stream');

        fwrite($handle, $csv);
        rewind($handle);

        $rowIndex = 0;
        $rowsSeen = 0;

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowsSeen++;

                foreach ($row as $colIndex => $cell) {
                    self::assertFalse(
                        $this->trustBoundaryFormulaShapedCell(is_string($cell) ? $cell : (string) $cell),
                        $message." — row {$rowIndex} col {$colIndex} carries a formula-shaped cell"
                    );
                }

                $rowIndex++;
            }
        } finally {
            fclose($handle);
        }

        self::assertGreaterThan(0, $rowsSeen, $message.' — export produced no rows');
    }

    /**
     * Boundary B invariant: a guest-visible response must never carry a
     * capability-bearing flash key or a concrete bearer secret (token).
     */
    public function assertResponseExposesNoCapabilityTokens(TestResponse $response, array $secrets = [], string $message = 'trust-boundary B: capability value reached an unauthorized response'): void
    {
        $body = (string) $response->getContent();

        foreach (self::TRUST_CAPABILITY_KEYS as $key) {
            self::assertStringNotContainsString($key, $body, $message." — link key «{$key}» present in response body");
        }

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString((string) $secret, $body, $message.' — bearer secret present in response body');
        }
    }

    /**
     * Boundary B invariant (session half): none of the capability-bearing
     * flash keys is present after the request under test.
     */
    public function assertSessionExposesNoCapabilityKeys(?array $keys = null, string $message = 'trust-boundary B: capability-bearing flash key present'): void
    {
        foreach ($keys ?? self::TRUST_CAPABILITY_KEYS as $key) {
            self::assertNull(session($key), $message." — session key «{$key}» present");
        }
    }

    /**
     * Boundary C invariant: a destructive money/access transition is gated by
     * authority. Runs the probe acting as every role in $forbiddenRoles
     * (use 'guest' for unauthenticated) and asserts FALSE, then as every
     * role in $allowedRoles and asserts TRUE.
     *
     * Scope note: this proves the GATE truth table (the authority floor as
     * coded in the gate callable). The wiring that actually routes user
     * actions through that gate (Filament DeleteAction/DeleteBulkAction,
     * route middleware) must be covered by a component/feature test of the
     * surface itself — see H5084PaymentDeleteAdminOnlyTest for the payment
     * delete wiring; this helper alone cannot catch a bypass that never
     * calls the gate.
     *
     * @param  callable(): bool  $allowed
     * @param  list<string>  $forbiddenRoles
     * @param  list<string>  $allowedRoles
     */
    public function assertTransitionAuthorityGated(callable $allowed, array $forbiddenRoles, array $allowedRoles, string $message = 'trust-boundary C: destructive transition must be admin-gated'): void
    {
        foreach ($forbiddenRoles as $role) {
            if ($role === 'guest') {
                auth()->logout();
            } else {
                $this->actingAs(User::factory()->create(['role' => $role]));
            }

            self::assertFalse($allowed(), $message." — role «{$role}» must not be allowed");
        }

        foreach ($allowedRoles as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            self::assertTrue($allowed(), $message." — role «{$role}» must stay allowed");
        }
    }
}
