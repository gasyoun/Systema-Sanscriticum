<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Observability\ProbeOutcome;

/**
 * H5061 — seeded violations of the probe missingness contract.
 *
 * Each method deliberately implements one forbidden behavior. The contract
 * suite (ProbeMissingnessContractTest) feeds these to the contract
 * assertions and asserts REJECTION: that is the seeded-red receipt. The
 * clean ProbeOutcome-based implementations then pass the same assertions.
 */
final class SeededViolationProbes
{
    /**
     * Violation 1 — SILENT SKIP: missing configuration quietly reported as
     * a green value. This is the «missing configuration exits quietly» fail
     * class (H5061).
     */
    public static function silentSkip(bool $configPresent): ProbeOutcome
    {
        if (! $configPresent) {
            return ProbeOutcome::value(green: true, detail: 'config absent — quietly skipped');
        }

        return ProbeOutcome::value(green: true, detail: 'measured');
    }

    /**
     * Violation 2 — ABSENT-TO-ZERO COERCION: no observation is coerced to a
     * genuine zero and reported as a measured value.
     */
    public static function zeroCoercion(?int $observed): ProbeOutcome
    {
        $count = $observed ?? 0; // the seeded defect

        return ProbeOutcome::value(green: $count < 5, detail: 'count='.$count);
    }

    /**
     * Violation 3 — STICKY FAILURE THAT NEVER CLEARS: once failed, the
     * state stays failed forever even after a green run. $state is carried
     * by the caller so the defect is observable.
     *
     * @param  array<string, bool>  $state
     */
    public static function stickyNeverClears(bool $healthy, array &$state): bool
    {
        if (! $healthy) {
            $state['failed'] = true;
        }
        // Seeded defect: the green branch forgets to unset $state['failed'].

        return $state['failed'] ?? false;
    }

    /** Compliant counterpart of silentSkip() for the green receipt. */
    public static function silentSkipCompliant(bool $configPresent): ProbeOutcome
    {
        if (! $configPresent) {
            return ProbeOutcome::notSupported('config absent — loud, machine-readable marker emitted');
        }

        return ProbeOutcome::value(green: true, detail: 'measured');
    }

    /** Compliant counterpart of zeroCoercion() for the green receipt. */
    public static function zeroCoercionCompliant(?int $observed): ProbeOutcome
    {
        if ($observed === null) {
            return ProbeOutcome::unavailable('no observation in window — NOT a zero');
        }

        return ProbeOutcome::value(green: $observed < 5, detail: 'count='.$observed);
    }
}
