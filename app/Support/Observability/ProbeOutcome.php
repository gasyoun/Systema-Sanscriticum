<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * H5061 — canonical missingness vocabulary for probe/SLI seams.
 *
 * Extracted from the contract demonstrated by #2526, #2565 and #2645
 * (H4648 coverage-partial, H4672 money-axis SLI): a probe result must
 * NEVER conflate «measured and green» with «could not measure». Every
 * active seam maps onto exactly one of these states:
 *
 *  - value         — measured; carries the observation (a genuine 0 IS a value)
 *  - unavailable   — the probed thing is unreachable/down (not a 0)
 *  - not_supported — the seam is not armed/configured on this machine
 *  - pending       — not yet measured (first run, window still open)
 *  - failed        — measured and broken (subject failed or probe itself errored)
 *  - partial       — ran green but covered only part of its surfaces (H4648)
 *
 * Missing data must never surface as value/0, and a green run must be
 * distinguishable from a skipped one. Enforced by
 * tests/Feature/Support/ProbeMissingnessContractTest.php (seeded red /
 * clean green receipts), reusable via Tests\Concerns\AssertsProbeMissingnessContract.
 */
final class ProbeOutcome
{
    public const VALUE = 'value';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_SUPPORTED = 'not_supported';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const PARTIAL = 'partial';

    /** @var list<string> */
    public const ALL = [
        self::VALUE,
        self::UNAVAILABLE,
        self::NOT_SUPPORTED,
        self::PENDING,
        self::FAILED,
        self::PARTIAL,
    ];

    /**
     * @param  list<string>|null  $covered  optional sub-checks actually covered (partial analysis)
     */
    private function __construct(
        public readonly string $state,
        public readonly bool $green,
        public readonly string $detail = '',
        public readonly array $covered = [],
    ) {}

    public static function value(bool $green = true, string $detail = ''): self
    {
        return new self(self::VALUE, $green, $detail);
    }

    public static function unavailable(string $detail = ''): self
    {
        return new self(self::UNAVAILABLE, false, $detail);
    }

    public static function notSupported(string $detail = ''): self
    {
        return new self(self::NOT_SUPPORTED, false, $detail);
    }

    public static function pending(string $detail = ''): self
    {
        return new self(self::PENDING, false, $detail);
    }

    public static function failed(string $detail = ''): self
    {
        return new self(self::FAILED, false, $detail);
    }

    /**
     * Partial coverage (H4648): no critical failure, but the run did NOT
     * cover every configured surface. Deliberately NOT green — partial must
     * stay machine-distinguishable from full coverage.
     *
     * @param  list<string>  $covered
     */
    public static function partial(string $detail = '', array $covered = []): self
    {
        return new self(self::PARTIAL, false, $detail, $covered);
    }

    /** «Could not measure» states — must never be coerced to value/0. */
    public function isMissing(): bool
    {
        return in_array($this->state, [self::UNAVAILABLE, self::NOT_SUPPORTED, self::PENDING], true);
    }

    public function isGreen(): bool
    {
        return $this->green;
    }

    /** @return array{state: string, green: bool, detail: string, covered: list<string>} machine-readable record */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'green' => $this->green,
            'detail' => $this->detail,
            'covered' => $this->covered,
        ];
    }

    public function __toString(): string
    {
        return $this->state.($this->detail !== '' ? " ({$this->detail})" : '');
    }
}
