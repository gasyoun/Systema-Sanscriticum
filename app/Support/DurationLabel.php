<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Целая русская давность без дробных дней.
 *
 * Дни — только пока меньше недели; дальше недели, месяцы, годы.
 * Carbon 3 отдаёт float в diffInDays (отсюда «119.24639666896 дн.»).
 */
final class DurationLabel
{
    public static function since(DateTimeInterface|string $from, DateTimeInterface|string|null $to = null): string
    {
        $fromAt = Carbon::parse($from);
        $toAt = $to === null ? now() : Carbon::parse($to);
        $days = (int) $fromAt->diff($toAt)->days;

        if ($days < 7) {
            return $days.' '.Plural::ru($days, 'день', 'дня', 'дней');
        }

        if ($days < 30) {
            $weeks = max(1, (int) round($days / 7));

            return $weeks.' '.Plural::ru($weeks, 'неделю', 'недели', 'недель');
        }

        if ($days < 365) {
            $months = max(1, (int) round($days / 30));

            return $months.' '.Plural::ru($months, 'месяц', 'месяца', 'месяцев');
        }

        $years = max(1, (int) round($days / 365));

        return $years.' '.Plural::ru($years, 'год', 'года', 'лет');
    }
}
