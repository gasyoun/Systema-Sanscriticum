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

        // Red-receipt pinning (gate review round 1): a flag, never a sentinel
        // self::fail() inside the try — an AssertionFailedError thrown there
        // would be swallowed by this same catch and the receipt would pass
        // green even if the contract assertion regressed to vacuous.
        $rejected = false;
        try {
            $this->assertMissingConfigIsLoud($seeded);
        } catch (AssertionFailedError) {
            $rejected = true; // red receipt: contract detected the violation
        }
        self::assertTrue(
            $rejected,
            'contract assertion became vacuous: seeded silent-skip was NOT rejected (red receipt unattainable)',
        );

        // Compliant counterpart passes the same assertion (green receipt).
        $this->assertMissingConfigIsLoud(SeededViolationProbes::silentSkipCompliant(configPresent: false));
    }

    public function test_seeded_zero_coercion_turns_the_contract_red(): void
    {
        $seeded = SeededViolationProbes::zeroCoercion(observed: null);

        $rejected = false;
        try {
            $this->assertNoAbsentToZeroCoercion($seeded);
        } catch (AssertionFailedError) {
            $rejected = true; // red receipt
        }
        self::assertTrue(
            $rejected,
            'contract assertion became vacuous: seeded absent-to-zero coercion was NOT rejected (red receipt unattainable)',
        );

        $this->assertNoAbsentToZeroCoercion(SeededViolationProbes::zeroCoercionCompliant(observed: null));
    }

    public function test_seeded_sticky_failure_that_never_clears_turns_the_contract_red(): void
    {
        $seededState = [];

        $rejected = false;
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
        } catch (AssertionFailedError) {
            $rejected = true; // red receipt
        }
        self::assertTrue(
            $rejected,
            'contract assertion became vacuous: seeded sticky-never-clears was NOT rejected (red receipt unattainable)',
        );

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

    /**
     * H5298 wave 2 — dynamic (non-command) seams join the same contract.
     * Seeded: an unarmed geo driver reported as a resolved value (the old
     * VisitorGeoResolver silent branch, baked in permanently by the geo jobs'
     * resolved_at marker) must be REJECTED; the loud not_supported counterpart
     * passes.
     */
    public function test_seeded_unarmed_dynamic_driver_as_value_turns_the_contract_red(): void
    {
        $seeded = SeededViolationProbes::dynamicUnarmedDriverAsValue(driverArmed: false);

        $rejected = false;
        try {
            $this->assertMissingConfigIsLoud($seeded);
        } catch (AssertionFailedError) {
            $rejected = true; // red receipt: contract detected the violation
        }
        self::assertTrue(
            $rejected,
            'contract assertion became vacuous: seeded unarmed-driver-as-value was NOT rejected (red receipt unattainable)',
        );

        // Compliant counterpart: unarmed driver is a loud not_supported.
        $this->assertMissingConfigIsLoud(SeededViolationProbes::dynamicUnarmedDriverAsValueCompliant(driverArmed: false));
    }

    /**
     * H5298 wave 2 — a queued metric write whose source row vanished must NOT
     * surface as a recorded value. Seeded: old TrackLessonViewJob silent
     * return coerced «source gone» to a green record; rejected. The loud
     * unavailable counterpart passes.
     */
    public function test_seeded_vanished_source_recorded_as_value_turns_the_contract_red(): void
    {
        $seeded = SeededViolationProbes::dynamicVanishedSourceAsValue(sourceExists: false);

        $rejected = false;
        try {
            $this->assertNoAbsentToZeroCoercion($seeded, ProbeOutcome::UNAVAILABLE);
        } catch (AssertionFailedError) {
            $rejected = true; // red receipt: contract detected the violation
        }
        self::assertTrue(
            $rejected,
            'contract assertion became vacuous: seeded vanished-source-as-value was NOT rejected (red receipt unattainable)',
        );

        // Compliant counterpart: vanished source is a loud unavailable.
        $this->assertNoAbsentToZeroCoercion(
            SeededViolationProbes::dynamicVanishedSourceAsValueCompliant(sourceExists: false),
            ProbeOutcome::UNAVAILABLE,
        );
    }
}
