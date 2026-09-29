<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Support\Observability\ProbeOutcome;

/**
 * H5061 — reusable contract assertions for the probe missingness vocabulary.
 *
 * Every active probe/SLI seam must be able to answer these three questions
 * with a machine-checkable yes. The seeded-violation fixtures in
 * Tests\Support\SeededViolationProbes violate each one; the contract test
 * turns red on them and green on the canonical ProbeOutcome helper.
 */
trait AssertsProbeMissingnessContract
{
    /**
     * Vocabulary completeness: all six canonical states are representable and
     * pairwise distinct, so «value» can never silently stand in for a
     * missing/degraded state.
     *
     * @param  array<string, ProbeOutcome>  $stateByScenario
     */
    protected function assertVocabularyDistinguishesAllStates(array $stateByScenario): void
    {
        $states = [];
        foreach ($stateByScenario as $scenario => $outcome) {
            self::assertContains($outcome->state, ProbeOutcome::ALL, "scenario «{$scenario}» returned a non-canonical state");
            $states[$scenario] = $outcome->state;
        }

        self::assertCount(count(ProbeOutcome::ALL), array_unique($states), 'every canonical state must be representable');
        self::assertTrue(ProbeOutcome::value()->isGreen(), 'value must be the green state');
        self::assertFalse(ProbeOutcome::partial()->isGreen(), 'partial must NOT be green — partial coverage must stay distinguishable from full');
        foreach (ProbeOutcome::ALL as $state) {
            if ($state !== ProbeOutcome::VALUE) {
                self::assertFalse((new \ReflectionClass(ProbeOutcome::class))->hasConstant($state) && ProbeOutcome::value()->state === $state);
            }
        }
    }

    /**
     * Missing configuration must surface as an explicit missing state,
     * machine-readable — never as a quiet success.
     */
    protected function assertMissingConfigIsLoud(ProbeOutcome $outcome, string $expectedState = ProbeOutcome::NOT_SUPPORTED): void
    {
        self::assertSame($expectedState, $outcome->state, 'missing configuration must map to the expected missing state');
        self::assertFalse($outcome->isGreen(), 'missing configuration must not be coerced to green');
        self::assertTrue($outcome->isMissing(), 'missing configuration must be a recognisable missing state');
    }

    /**
     * Absent data must never be coerced to value/0.
     */
    protected function assertNoAbsentToZeroCoercion(ProbeOutcome $outcome, string $expectedState = ProbeOutcome::UNAVAILABLE): void
    {
        self::assertNotSame(ProbeOutcome::VALUE, $outcome->state, 'absent data must never surface as value/0');
        self::assertSame($expectedState, $outcome->state);
    }

    /**
     * A sticky failure state must clear when a green run arrives.
     *
     * @param  callable(): void  $failRun
     * @param  callable(): void  $greenRun
     * @param  callable(): bool  $readSticky
     */
    protected function assertStickyStateClearsOnGreen(callable $failRun, callable $greenRun, callable $readSticky): void
    {
        $failRun();
        self::assertTrue($readSticky(), 'seed: sticky failure state must be observable after a failing run');

        $greenRun();
        self::assertFalse($readSticky(), 'H5061: sticky failure state must clear on a green run (recovery must be observable)');
    }
}
