<?php

declare(strict_types=1);

namespace App\Services\Srs;

use DateTimeImmutable;

/**
 * Чистое значение-объект: снимок FSRS-состояния одной карточки для конкретного
 * пользователя. Не привязан к Eloquent — {@see ReviewService}
 * маппит его в/из строки srs_review_states. Порт `Card` из открытой реализации
 * FSRS (open-spaced-repetition/py-fsrs).
 */
class FsrsCard
{
    public function __construct(
        public State $state = State::Learning,
        public ?int $step = 0,
        public ?float $stability = null,
        public ?float $difficulty = null,
        public ?DateTimeImmutable $due = null,
        public ?DateTimeImmutable $lastReview = null,
    ) {}

    /** Свежая карточка, ещё ни разу не повторявшаяся (Learning, шаг 0). */
    public static function fresh(DateTimeImmutable $now): self
    {
        return new self(state: State::Learning, step: 0, due: $now);
    }

    public function copy(): self
    {
        return new self(
            $this->state,
            $this->step,
            $this->stability,
            $this->difficulty,
            $this->due,
            $this->lastReview,
        );
    }
}
