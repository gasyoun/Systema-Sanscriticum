<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MissingMilestoneCourseDetector;
use Illuminate\Console\Command;

/**
 * Ежедневный детектор курсов без вех сертификатов (>= N занятий состоялось,
 * вех нет → уведомление кураторам + пометка курса). Намеренно НЕ зависит от
 * гейта автовыдачи certificate_auto_issue_enabled: даже при выключенной
 * автовыдаче куратор должен узнать, что курс идёт без плана по сертификатам.
 */
class DetectMissingMilestoneCourses extends Command
{
    protected $signature = 'courses:detect-missing-milestones';

    protected $description = 'Помечает курсы с состоявшимися занятиями, но без вех сертификатов, и уведомляет кураторов.';

    public function handle(MissingMilestoneCourseDetector $detector): int
    {
        $courses = $detector->run();

        $this->info("Курсов без вех помечено: {$courses->count()}.");

        return self::SUCCESS;
    }
}
