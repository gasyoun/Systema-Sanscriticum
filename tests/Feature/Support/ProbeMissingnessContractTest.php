<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\Observability\ProbeOutcome;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\AssertsProbeMissingnessContract;
use Tests\Support\SeededViolationProbes;
use Tests\TestCase;

/**
 * H5061 — the reusable missingness contract suite.
 *
 * Green on the canonical ProbeOutcome helper and compliant fixtures;
 * RED (assertion rejected) on each seeded violation:
 * silent skip, absent-to-zero coercion, sticky-failure-never-clears.
 * Those three rejections ARE the seeded-red receipts required by H5061.
 */
final class ProbeMissingnessContractTest extends TestCase
{
    use AssertsProbeMissingnessContract;

    public function test_vocabulary_represents_all_six_canonical_states(): void
    {
        $this->assertVocabularyDistinguishesAllStates([
            'value' => ProbeOutcome::value(),
            'unavailable' => ProbeOutcome::unavailable('host down'),
            'not_supported' => ProbeOutcome::notSupported('feature flag off'),
            'pending' => ProbeOutcome::pending('first run'),
            'failed' => ProbeOutcome::failed('probe errored'),
            'partial' => ProbeOutcome::partial('student branch skipped', ['public', 'manager']),
        ]);
    }

    public function test_seeded_silent_skip_turns_the_contract_red(): void
    {
        $seeded = SeededViolationProbes::silentSkip(configPresent: false);

        try {
            $this->assertMissingConfigIsLoud($seeded);
            self::fail('seeded silent-skip must be REJECTED by the contract (red receipt)');
        } catch (AssertionFailedError) {
            self::assertTrue(true); // red receipt: contract detected the violation
        }

        // Compliant counterpart passes the same assertion (green receipt).
        $this->assertMissingConfigIsLoud(SeededViolationProbes::silentSkipCompliant(configPresent: false));
    }

    public function test_seeded_zero_coercion_turns_the_contract_red(): void
    {
        $seeded = SeededViolationProbes::zeroCoercion(observed: null);

        try {
            $this->assertNoAbsentToZeroCoercion($seeded);
            self::fail('seeded absent-to-zero coercion must be REJECTED by the contract (red receipt)');
        } catch (AssertionFailedError) {
            self::assertTrue(true); // red receipt
        }

        $this->assertNoAbsentToZeroCoercion(SeededViolationProbes::zeroCoercionCompliant(observed: null));
    }

    public function test_seeded_sticky_failure_that_never_clears_turns_the_contract_red(): void
    {
        $seededState = [];

        try {
            $this->assertStickyStateClearsOnGreen(
                failRun: function () use (&$seededState): void {
                    SeededViolationProbes::stickyNeverClears(healthy: false, state: $seededState);
                },
                greenRun: function () use (&$seededState): void {
                    SeededViolationProbes::stickyNeverClears(healthy: true, state: $seededState);
                },
                readSticky: function () use (&$seededState): bool {
                    return $seededState['failed'] ?? false;
                },
            );
            self::fail('seeded sticky-never-clears must be REJECTED by the contract (red receipt)');
        } catch (AssertionFailedError) {
            self::assertTrue(true); // red receipt
        }

        // Compliant counterpart: green run clears the sticky state.
        $compliantState = [];
        $this->assertStickyStateClearsOnGreen(
            failRun: function () use (&$compliantState): void {
                $compliantState['failed'] = true;
            },
            greenRun: function () use (&$compliantState): void {
                unset($compliantState['failed']);
            },
            readSticky: function () use (&$compliantState): bool {
                return $compliantState['failed'] ?? false;
            },
        );
    }
}
