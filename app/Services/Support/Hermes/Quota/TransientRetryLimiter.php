<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: лимит повторов — «максимум два повтора» на временную ошибку
 * (стратегия 04-10-2026 §4: «Повторы ≤2 … без рекурсивных агентных циклов»).
 *
 * 1 первая попытка + ≤2 повтора; третий повтор запрещён. Повторяется только
 * TransientCallException. Проверка итеративная (счётчик), не рекурсивная.
 */
final class TransientRetryLimiter
{
    public function __construct(private readonly int $maxRetries)
    {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0');
        }
    }

    /** Из конфига school_role_quota.max_retries (принятое значение: 2). */
    public static function fromConfig(): self
    {
        $raw = config('school_role_quota.max_retries');

        if (! is_int($raw) && ! ctype_digit((string) $raw)) {
            throw new \RuntimeException('school_role_quota.max_retries is not configured (config defect)');
        }

        return new self((int) $raw);
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Разрешён ли ещё повтор. $retriesSoFar — сколько повторов уже сделано
     * (0 после первой неудачной попытки).
     */
    public function shouldRetry(int $retriesSoFar, bool $transient): bool
    {
        return $transient && $retriesSoFar < $this->maxRetries;
    }

    /** Повторяема ли ошибка: только явный маркер временной ошибки. */
    public function isTransient(\Throwable $e): bool
    {
        return $e instanceof TransientCallException;
    }
}
