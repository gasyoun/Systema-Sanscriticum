<?php

declare(strict_types=1);

namespace App\Services\Discipline;

/**
 * Результат DisciplineScoreService::forUser() — не персистится, считается
 * on-demand (см. docs/discipline-score-spec.md §Архитектура).
 */
final class DisciplineScore
{
    public const TIER_GOOD = 'good';

    public const TIER_WATCH = 'watch';

    public const TIER_POOR = 'poor';

    /**
     * @param  array{on_time_rate: float, promise_keep_rate: float, credit_reliance_rate: float, silence_events: int}  $signals
     */
    public function __construct(
        public readonly int $score,
        public readonly string $tier,
        public readonly array $signals,
    ) {}

    public function label(): string
    {
        return match ($this->tier) {
            self::TIER_GOOD => 'Хорошо',
            self::TIER_WATCH => 'Наблюдение',
            self::TIER_POOR => 'Плохо',
            default => '—',
        };
    }

    public function color(): string
    {
        return match ($this->tier) {
            self::TIER_GOOD => 'success',
            self::TIER_WATCH => 'warning',
            self::TIER_POOR => 'danger',
            default => 'gray',
        };
    }
}
