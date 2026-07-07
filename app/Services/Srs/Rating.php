<?php

declare(strict_types=1);

namespace App\Services\Srs;

/**
 * Оценка ответа при повторении карточки (как в Anki/FSRS).
 * Целочисленные значения фиксированы протоколом FSRS — их пишут в srs_review_logs.rating.
 */
enum Rating: int
{
    case Again = 1;
    case Hard = 2;
    case Good = 3;
    case Easy = 4;
}
