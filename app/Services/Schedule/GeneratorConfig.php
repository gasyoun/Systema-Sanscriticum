<?php

declare(strict_types=1);

namespace App\Services\Schedule\DTO;

use Carbon\Carbon;

final class GeneratorConfig
{
    /**
     * @param  array<int>     $weekdays   Carbon::SUNDAY=0 .. SATURDAY=6
     * @param  array<string>  $skipDates  Y-m-d
     * @param  array<string>  $addDates   Y-m-d
     */
    public function __construct(
        public readonly int $groupId,
        public readonly ?int $courseId,
        public readonly string $title,
        public readonly Carbon $startDate,
        public readonly string $startTime,        // "HH:MM"
        public readonly int $durationMinutes,
        public readonly int $totalLessons,
        public readonly int $startNumber,         // для {N} в шаблоне
        public readonly int $startLessonIndex,    // для блочной нумерации {BLOCK}/{BN}
        public readonly array $weekdays,
        public readonly string $template,
        public readonly array $skipDates,
        public readonly array $addDates,
        public readonly ?string $link,
        public readonly bool $preserve,
    ) {}
}