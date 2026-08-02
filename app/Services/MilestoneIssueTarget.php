<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CertificateMilestone;
use App\Models\Group;

/**
 * Цель выдачи: (веха, группа, итерация). Block-вехи курс-уровневые — group NULL,
 * occurrence 1. Lesson-триггеры пер-групповые: разные группы доходят до N-го
 * занятия в разное время, повторяющиеся вехи дают occurrence 1, 2, 3...
 */
final class MilestoneIssueTarget
{
    public function __construct(
        public readonly CertificateMilestone $milestone,
        public readonly ?Group $group = null,
        public readonly int $occurrence = 1,
    ) {}

    /** Подпись для dry-run/логов: «Курс / веха / группа / итерация». */
    public function label(): string
    {
        $parts = [
            $this->milestone->course->title ?? "course#{$this->milestone->course_id}",
            "«{$this->milestone->title}»",
        ];
        if ($this->group) {
            $parts[] = $this->group->name;
        }
        if ($this->occurrence > 1 || $this->milestone->isRepeating()) {
            $parts[] = "итерация {$this->occurrence}";
        }

        return implode(' / ', $parts);
    }
}
