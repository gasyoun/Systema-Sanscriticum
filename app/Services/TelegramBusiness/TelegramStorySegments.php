<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use RuntimeException;

/** Exact source offsets for Telegram's 60-second video Story limit. */
final class TelegramStorySegments
{
    /** @return list<array{offset: int, duration: float}> */
    public static function plan(float $duration): array
    {
        if (! is_finite($duration) || $duration <= 0) {
            throw new RuntimeException('Story source video has no valid duration.');
        }
        // Container metadata may report a sub-millisecond tail beyond an exact boundary.
        $duration = round($duration, 3);
        if ($duration <= 0) {
            throw new RuntimeException('Story source video is shorter than one millisecond.');
        }
        $count = (int) ceil($duration / 60);
        if ($count > 10) {
            throw new RuntimeException("Story video requires {$count} parts; at most 10 are supported.");
        }

        $segments = [];
        for ($index = 0; $index < $count; $index++) {
            $offset = $index * 60;
            $segments[] = ['offset' => $offset, 'duration' => min(60.0, round($duration - $offset, 3))];
        }

        return $segments;
    }
}
