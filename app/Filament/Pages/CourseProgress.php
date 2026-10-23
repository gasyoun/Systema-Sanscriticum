<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\ScheduleResource;
use App\Services\Schedule\StreamProgressReport;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * «Ход курсов»: по каждому идущему потоку — какой блок и какое занятие в нём
 * сейчас («блок 3 · 2-е из 4»). Всё из расписания, см. StreamProgressReport.
 */
class CourseProgress extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-forward';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 42;

    protected static ?string $navigationLabel = 'Ход курсов';

    protected static ?string $title = 'Ход курсов по блокам';

    protected static ?string $slug = 'course-progress';

    protected static string $view = 'filament.pages.course-progress';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $rows = collect(app(StreamProgressReport::class)->rows())
            ->map(fn (array $row) => $row + [
                'course_url' => $row['course_id'] !== null
                    ? CourseResource::getUrl('edit', ['record' => $row['course_id']])
                    : null,
            ]);

        return [
            'rows' => $rows,
            'scheduleUrl' => ScheduleResource::getUrl('index'),
            'blockSize' => StreamProgressReport::BLOCK_SIZE,
            'windowDays' => StreamProgressReport::WINDOW_DAYS,
        ];
    }
}
