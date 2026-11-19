<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CourseBlock;
use App\Services\Calendar\CourseDatePropagationService;

/**
 * Каскад дат блока курса (H6369, Phase 4 роадмапа Google-календаря):
 * только правка starts_at у CourseBlock сдвигает будущие занятия Schedule
 * его курса по правилу translate — сервис CourseDatePropagationService.
 * Правка ends_at расписание не меняет.
 *
 * Модельный уровень, чтобы срабатывало с любой поверхности (Filament, tinker).
 * Уведомления группам и перенос Zoom-встреч — вне скоупа (Phase 3-каскад).
 */
class CourseBlockObserver
{
    public function __construct(
        private readonly CourseDatePropagationService $propagation,
    ) {}

    public function updated(CourseBlock $block): void
    {
        if (! $block->wasChanged('starts_at')) {
            return;
        }

        $this->propagation->propagate($block);
    }
}
