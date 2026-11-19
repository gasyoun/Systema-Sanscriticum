<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Quota;

/**
 * H6174: снимок квоты школьной роли на окно — счётчики по полосам 60/20/20 и
 * режим по принятым порогам 79/80/99/100 процентов.
 *
 * Сравнение порогов — целочисленное (used×100 ≥ pct×allowance), чтобы
 * границы 79/80/99/100 не дрейфовали от float-арифметики.
 */
final class QuotaStatus
{
    public const MODE_OK = 'ok';

    public const MODE_WARN = 'warn';

    public const MODE_EXPERIMENTS_STOPPED = 'experiments_stopped';

    public const MODE_CRITICAL = 'critical';

    public const MODE_HARD_STOP = 'hard_stop';

    public const MODE_UNMEASURED = 'unmeasured';

    /**
     * @param  array<string, int>  $laneCommitted
     * @param  array<string, int>  $laneReserved
     */
    public function __construct(
        public readonly string $roleId,
        public readonly string $windowKey,
        public readonly ?int $allowance,
        public readonly int $committedTokens,
        public readonly int $reservedTokens,
        public readonly array $laneCommitted,
        public readonly array $laneReserved,
    ) {}

    /** Расход (закоммичено + удерживается резервами) — счётчик до вычета возвратов. */
    public function usedTokens(): int
    {
        return $this->committedTokens + $this->reservedTokens;
    }

    /** Расход в процентах (для показа); проверки порогов — через crossesPct(). */
    public function usedPct(): ?float
    {
        if ($this->allowance === null || $this->allowance <= 0) {
            return null;
        }

        return round(100.0 * $this->usedTokens() / $this->allowance, 2);
    }

    /** used×100 ≥ pct×allowance — точное пороговое сравнение без float-дрейфа. */
    public function crossesPct(int $pct): bool
    {
        if ($this->allowance === null || $this->allowance <= 0) {
            return false;
        }

        return $this->usedTokens() * 100 >= $pct * $this->allowance;
    }

    /** Потолок полосы в токенах; null — квота неизмерена. */
    public function laneCap(QuotaLane $lane): ?int
    {
        return $this->allowance === null ? null : $lane->cap($this->allowance);
    }

    /**
     * Режим по принятым порогам 79/80/99/100 (config school_role_quota):
     * 100 — ноль LLM-вызовов; 80 — стоп экспериментов; 99/79 — предупреждения.
     */
    public function mode(): string
    {
        if ($this->allowance === null) {
            return self::MODE_UNMEASURED;
        }

        if ($this->crossesPct(QuotaConfig::threshold('hard_stop_pct'))) {
            return self::MODE_HARD_STOP;
        }

        if ($this->crossesPct(QuotaConfig::threshold('critical_pct'))) {
            return self::MODE_CRITICAL;
        }

        if ($this->crossesPct(QuotaConfig::threshold('experiments_stop_pct'))) {
            return self::MODE_EXPERIMENTS_STOPPED;
        }

        if ($this->crossesPct(QuotaConfig::threshold('warn_pct'))) {
            return self::MODE_WARN;
        }

        return self::MODE_OK;
    }

    /** Эксперименты запрещены при расходе ≥80% (включая critical/hard_stop). */
    public function experimentsStopped(): bool
    {
        return in_array($this->mode(), [
            self::MODE_EXPERIMENTS_STOPPED,
            self::MODE_CRITICAL,
            self::MODE_HARD_STOP,
        ], true);
    }

    /**
     * Предупреждения на порогах 79 и 99 (срезки 80 и 100 впереди).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        if ($this->allowance === null) {
            return [];
        }

        $warnings = [];

        if ($this->crossesPct(QuotaConfig::threshold('critical_pct'))) {
            $warnings[] = 'approaching_hard_cutoff_100';
        }

        if ($this->crossesPct(QuotaConfig::threshold('warn_pct'))) {
            $warnings[] = 'approaching_experiments_cutoff_80';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role_id' => $this->roleId,
            'window_key' => $this->windowKey,
            'allowance' => $this->allowance,
            'committed' => $this->committedTokens,
            'reserved' => $this->reservedTokens,
            'used' => $this->usedTokens(),
            'used_pct' => $this->usedPct(),
            'mode' => $this->mode(),
            'warnings' => $this->warnings(),
            'lane_committed' => $this->laneCommitted,
            'lane_reserved' => $this->laneReserved,
        ];
    }
}
