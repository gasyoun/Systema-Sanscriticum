<?php

declare(strict_types=1);

namespace App\Services\Srs;

/**
 * Фаза карточки в FSRS. Значения фиксированы протоколом и хранятся в
 * srs_review_states.state / srs_review_logs.state.
 */
enum State: int
{
    case Learning = 1;
    case Review = 2;
    case Relearning = 3;
}
