<?php

declare(strict_types=1);

namespace App\Services\Srs;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * FSRS-планировщик (Free Spaced Repetition Scheduler).
 *
 * Дословный порт эталонной открытой реализации
 * {@link https://github.com/open-spaced-repetition/py-fsrs} (FSRS-6, 21 вес) на PHP.
 * Ничего не «изобретаем» — формулы, константы и веса перенесены как есть, а
 * численная точность подтверждается эталонными векторами в `FsrsTest`
 * (те же значения, что в py-fsrs `tests/test_basic.py`).
 *
 * Замечание о версии: handoff H211 (05-07-2026) называл «19 весов» (FSRS-5); к моменту
 * реализации (07-07-2026) эталон перешёл на FSRS-6 (21 вес, decay — обучаемый вес №20).
 * Зафиксированное решение — «вендорить эталон, не катать своё» — поэтому взята текущая
 * версия эталона.
 */
class Fsrs
{
    /** Веса FSRS-6 по умолчанию (индекс 20 — decay). */
    public const DEFAULT_PARAMETERS = [
        0.212, 1.2931, 2.3065, 8.2956, 6.4133, 0.8334, 3.0194, 0.001,
        1.8722, 0.1666, 0.796, 1.4835, 0.0614, 0.2629, 1.6483, 0.6014,
        1.8729, 0.5425, 0.0912, 0.0658, 0.1542,
    ];

    private const STABILITY_MIN = 0.001;

    private const MIN_DIFFICULTY = 1.0;

    private const MAX_DIFFICULTY = 10.0;

    /** Диапазоны «размытия» интервала (fuzz) — как в эталоне. */
    private const FUZZ_RANGES = [
        ['start' => 2.5, 'end' => 7.0, 'factor' => 0.15],
        ['start' => 7.0, 'end' => 20.0, 'factor' => 0.1],
        ['start' => 20.0, 'end' => INF, 'factor' => 0.05],
    ];

    /** @var float[] */
    private array $parameters;

    private float $decay;

    private float $factor;

    /**
     * @param  float[]  $parameters  21 вес FSRS (по умолчанию — эталонные)
     * @param  int[]  $learningSteps  Под-дневные шаги обучения, в секундах
     * @param  int[]  $relearningSteps  Под-дневные шаги переобучения, в секундах
     */
    public function __construct(
        array $parameters = self::DEFAULT_PARAMETERS,
        private float $desiredRetention = 0.9,
        private array $learningSteps = [60, 600],       // 1 мин, 10 мин
        private array $relearningSteps = [600],          // 10 мин
        private int $maximumInterval = 36500,
        private bool $enableFuzzing = true,
    ) {
        if (count($parameters) !== count(self::DEFAULT_PARAMETERS)) {
            throw new InvalidArgumentException(
                sprintf('Expected %d parameters, got %d.', count(self::DEFAULT_PARAMETERS), count($parameters))
            );
        }

        $this->parameters = array_values($parameters);
        $this->decay = -$this->parameters[20];
        $this->factor = 0.9 ** (1 / $this->decay) - 1;
    }

    /**
     * Предсказанная вероятность вспомнить карточку на момент $now (0..1).
     */
    public function getCardRetrievability(FsrsCard $card, DateTimeImmutable $now): float
    {
        if ($card->lastReview === null || $card->stability === null) {
            return 0.0;
        }

        $elapsedDays = max(0, $this->elapsedDays($card->lastReview, $now));

        return (1 + $this->factor * $elapsedDays / $card->stability) ** $this->decay;
    }

    /**
     * Оценивает карточку и возвращает НОВЫЙ {@see FsrsCard} с обновлёнными
     * stability/difficulty/state/step/due/lastReview. Исходный объект не мутируется.
     */
    public function reviewCard(FsrsCard $card, Rating $rating, DateTimeImmutable $reviewDatetime): FsrsCard
    {
        $card = $card->copy();

        $daysSinceLastReview = $card->lastReview !== null
            ? $this->elapsedDays($card->lastReview, $reviewDatetime)
            : null;

        /** @var int $nextIntervalSeconds */
        $nextIntervalSeconds = 0;

        switch ($card->state) {
            case State::Learning:
                if ($card->stability === null || $card->difficulty === null) {
                    $card->stability = $this->initialStability($rating);
                    $card->difficulty = $this->initialDifficulty($rating, true);
                } elseif ($daysSinceLastReview !== null && $daysSinceLastReview < 1) {
                    $card->stability = $this->shortTermStability($card->stability, $rating);
                    $card->difficulty = $this->nextDifficulty($card->difficulty, $rating);
                } else {
                    $card->stability = $this->nextStability(
                        $card->difficulty,
                        $card->stability,
                        $this->getCardRetrievability($card, $reviewDatetime),
                        $rating,
                    );
                    $card->difficulty = $this->nextDifficulty($card->difficulty, $rating);
                }

                if (count($this->learningSteps) === 0
                    || ($card->step >= count($this->learningSteps)
                        && in_array($rating, [Rating::Hard, Rating::Good, Rating::Easy], true))
                ) {
                    $card->state = State::Review;
                    $card->step = null;
                    $nextIntervalSeconds = $this->nextIntervalDays($card->stability) * 86400;
                } else {
                    $nextIntervalSeconds = $this->learningStepInterval(
                        $this->learningSteps, $card, $rating
                    );
                }
                break;

            case State::Review:
                if ($daysSinceLastReview !== null && $daysSinceLastReview < 1) {
                    $card->stability = $this->shortTermStability($card->stability, $rating);
                } else {
                    $card->stability = $this->nextStability(
                        $card->difficulty,
                        $card->stability,
                        $this->getCardRetrievability($card, $reviewDatetime),
                        $rating,
                    );
                }
                $card->difficulty = $this->nextDifficulty($card->difficulty, $rating);

                if ($rating === Rating::Again) {
                    if (count($this->relearningSteps) === 0) {
                        $nextIntervalSeconds = $this->nextIntervalDays($card->stability) * 86400;
                    } else {
                        $card->state = State::Relearning;
                        $card->step = 0;
                        $nextIntervalSeconds = $this->relearningSteps[0];
                    }
                } else {
                    $nextIntervalSeconds = $this->nextIntervalDays($card->stability) * 86400;
                }
                break;

            case State::Relearning:
                if ($daysSinceLastReview !== null && $daysSinceLastReview < 1) {
                    $card->stability = $this->shortTermStability($card->stability, $rating);
                    $card->difficulty = $this->nextDifficulty($card->difficulty, $rating);
                } else {
                    $card->stability = $this->nextStability(
                        $card->difficulty,
                        $card->stability,
                        $this->getCardRetrievability($card, $reviewDatetime),
                        $rating,
                    );
                    $card->difficulty = $this->nextDifficulty($card->difficulty, $rating);
                }

                if (count($this->relearningSteps) === 0
                    || ($card->step >= count($this->relearningSteps)
                        && in_array($rating, [Rating::Hard, Rating::Good, Rating::Easy], true))
                ) {
                    $card->state = State::Review;
                    $card->step = null;
                    $nextIntervalSeconds = $this->nextIntervalDays($card->stability) * 86400;
                } else {
                    $nextIntervalSeconds = $this->learningStepInterval(
                        $this->relearningSteps, $card, $rating
                    );
                }
                break;
        }

        if ($this->enableFuzzing && $card->state === State::Review) {
            $nextIntervalSeconds = $this->fuzzedIntervalDays(intdiv($nextIntervalSeconds, 86400)) * 86400;
        }

        $card->due = $reviewDatetime->modify(sprintf('+%d seconds', $nextIntervalSeconds));
        $card->lastReview = $reviewDatetime;

        return $card;
    }

    /**
     * Общая логика под-дневных шагов (learning/relearning): мутирует $card->step
     * и/или $card->state и возвращает интервал в секундах.
     *
     * @param  int[]  $steps
     */
    private function learningStepInterval(array $steps, FsrsCard $card, Rating $rating): int
    {
        switch ($rating) {
            case Rating::Again:
                $card->step = 0;

                return $steps[0];

            case Rating::Hard:
                if ($card->step === 0 && count($steps) === 1) {
                    return (int) round($steps[0] * 1.5);
                }
                if ($card->step === 0 && count($steps) >= 2) {
                    return (int) round(($steps[0] + $steps[1]) / 2.0);
                }

                return $steps[$card->step];

            case Rating::Good:
                if ($card->step + 1 === count($steps)) {
                    $card->state = State::Review;
                    $card->step = null;

                    return $this->nextIntervalDays($card->stability) * 86400;
                }
                $card->step++;

                return $steps[$card->step];

            case Rating::Easy:
                $card->state = State::Review;
                $card->step = null;

                return $this->nextIntervalDays($card->stability) * 86400;
        }

        return $steps[0];
    }

    private function initialStability(Rating $rating): float
    {
        return $this->clampStability($this->parameters[$rating->value - 1]);
    }

    private function initialDifficulty(Rating $rating, bool $clamp): float
    {
        $difficulty = $this->parameters[4] - (M_E ** ($this->parameters[5] * ($rating->value - 1))) + 1;

        return $clamp ? $this->clampDifficulty($difficulty) : $difficulty;
    }

    private function nextIntervalDays(float $stability): int
    {
        $nextInterval = ($stability / $this->factor) * (($this->desiredRetention ** (1 / $this->decay)) - 1);
        $nextInterval = (int) round($nextInterval);
        $nextInterval = max($nextInterval, 1);

        return min($nextInterval, $this->maximumInterval);
    }

    private function shortTermStability(float $stability, Rating $rating): float
    {
        $increase = (M_E ** ($this->parameters[17] * ($rating->value - 3 + $this->parameters[18])))
            * ($stability ** -$this->parameters[19]);

        if ($rating === Rating::Good || $rating === Rating::Easy) {
            $increase = max($increase, 1.0);
        }

        return $this->clampStability($stability * $increase);
    }

    private function nextDifficulty(float $difficulty, Rating $rating): float
    {
        $argEasy = $this->initialDifficulty(Rating::Easy, false);

        $deltaDifficulty = -($this->parameters[6] * ($rating->value - 3));
        $linearDamping = (10.0 - $difficulty) * $deltaDifficulty / 9.0;
        $arg2 = $difficulty + $linearDamping;

        $next = $this->parameters[7] * $argEasy + (1 - $this->parameters[7]) * $arg2;

        return $this->clampDifficulty($next);
    }

    private function nextStability(float $difficulty, float $stability, float $retrievability, Rating $rating): float
    {
        if ($rating === Rating::Again) {
            $next = $this->nextForgetStability($difficulty, $stability, $retrievability);
        } else {
            $next = $this->nextRecallStability($difficulty, $stability, $retrievability, $rating);
        }

        return $this->clampStability($next);
    }

    private function nextForgetStability(float $difficulty, float $stability, float $retrievability): float
    {
        $longTerm = $this->parameters[11]
            * ($difficulty ** -$this->parameters[12])
            * ((($stability + 1) ** $this->parameters[13]) - 1)
            * (M_E ** ((1 - $retrievability) * $this->parameters[14]));

        $shortTerm = $stability / (M_E ** ($this->parameters[17] * $this->parameters[18]));

        return min($longTerm, $shortTerm);
    }

    private function nextRecallStability(float $difficulty, float $stability, float $retrievability, Rating $rating): float
    {
        $hardPenalty = $rating === Rating::Hard ? $this->parameters[15] : 1;
        $easyBonus = $rating === Rating::Easy ? $this->parameters[16] : 1;

        return $stability * (
            1
            + (M_E ** $this->parameters[8])
            * (11 - $difficulty)
            * ($stability ** -$this->parameters[9])
            * ((M_E ** ((1 - $retrievability) * $this->parameters[10])) - 1)
            * $hardPenalty
            * $easyBonus
        );
    }

    private function fuzzedIntervalDays(int $intervalDays): int
    {
        if ($intervalDays < 2.5) {
            return $intervalDays;
        }

        $delta = 1.0;
        foreach (self::FUZZ_RANGES as $range) {
            $delta += $range['factor'] * max(min((float) $intervalDays, $range['end']) - $range['start'], 0.0);
        }

        $minIvl = (int) round($intervalDays - $delta);
        $maxIvl = (int) round($intervalDays + $delta);
        $minIvl = max(2, $minIvl);
        $maxIvl = min($maxIvl, $this->maximumInterval);
        $minIvl = min($minIvl, $maxIvl);

        $fuzzed = (mt_rand() / mt_getrandmax()) * ($maxIvl - $minIvl + 1) + $minIvl;

        return min((int) round($fuzzed), $this->maximumInterval);
    }

    private function clampStability(float $stability): float
    {
        return max($stability, self::STABILITY_MIN);
    }

    private function clampDifficulty(float $difficulty): float
    {
        return min(max($difficulty, self::MIN_DIFFICULTY), self::MAX_DIFFICULTY);
    }

    /**
     * Целых дней между двумя моментами (пол, как timedelta.days в Python для
     * неотрицательной разницы).
     */
    private function elapsedDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }
}
