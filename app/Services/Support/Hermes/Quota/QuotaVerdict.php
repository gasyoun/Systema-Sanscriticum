<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: вердикт квотной машины — контракт в духе killgate H5867
 * (status/code/reason), плюс фиксированный fallback=none: платного fallback
 * нет (стратегия §4), при отказе вызов уходит в очередь человеку.
 */
final class QuotaVerdict
{
    public const STATUS_OK = 'ok';

    public const STATUS_UNMEASURED = 'unmeasured';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DENY = 'deny';

    public const CODE_OK = 'ok';

    public const CODE_IDEMPOTENT_REPLAY = 'idempotent_replay';

    public const CODE_UNMEASURED = 'unmeasured';

    public const CODE_DISABLED = 'disabled';

    public const CODE_INVALID_ROLE_ID = 'invalid_role_id';

    public const CODE_EXCLUDED_ROLE = 'excluded_role';

    public const CODE_INVALID_UNITS = 'invalid_units';

    public const CODE_INVALID_ACTUAL_UNITS = 'invalid_actual_units';

    public const CODE_LANE_EXHAUSTED = 'lane_exhausted';

    public const CODE_EXPERIMENTS_STOPPED = 'experiments_stopped';

    public const CODE_HARD_STOP = 'hard_stop';

    public const CODE_RETRIES_EXHAUSTED = 'retries_exhausted';

    public const CODE_TASK_FAILED = 'task_failed';

    public const CODE_NO_RESERVATION = 'no_reservation';

    public const CODE_ALREADY_COMMITTED = 'already_committed';

    public const CODE_ALREADY_RELEASED = 'already_released';

    /** Платного fallback нет (H6174: «платного fallback нет») — константа, не режим. */
    public const FALLBACK_NONE = 'none';

    private function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $reason,
        public readonly bool $allowed,
        public readonly ?int $reservationId = null,
        public readonly bool $queuedForHuman = false,
    ) {}

    public static function allowed(string $status, string $code, string $reason, ?int $reservationId = null): self
    {
        return new self($status, $code, $reason, true, $reservationId, false);
    }

    /** Отказ всегда: fallback=none, вызов уходит в очередь человеку (не в платный API). */
    public static function denied(string $code, string $reason): self
    {
        return new self(self::STATUS_DENY, $code, $reason, false, null, true);
    }

    public function fallback(): string
    {
        return self::FALLBACK_NONE;
    }

    /**
     * @return array{status: string, code: string, reason: string, allowed: bool, fallback: string, queued_for_human: bool, reservation_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'reason' => $this->reason,
            'allowed' => $this->allowed,
            'fallback' => $this->fallback(),
            'queued_for_human' => $this->queuedForHuman,
            'reservation_id' => $this->reservationId,
        ];
    }
}
