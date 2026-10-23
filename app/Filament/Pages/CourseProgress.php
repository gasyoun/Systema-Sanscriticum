<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\ScheduleResource;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Support\CourseCadence;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «Ход курсов»: по каждому активному курсу — текущий блок и на каком занятии
 * этого блока сейчас каждый поток («5-е из 12»). Счёт идёт по календарю
 * (`schedules`) внутри дат блока, см. CourseCadence::blockStreams().
 * Курсы без текущего блока, у которых есть следующий, — отдельной таблицей
 * «между блоками».
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
        // Как Course::currentBlock(): из текущих блоков курса — младший по номеру.
        $current = CourseBlock::query()
            ->current()
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->orderBy('number')
            ->get()
            ->unique('course_id')
            ->values();

        $upcoming = CourseBlock::query()
            ->where('starts_at', '>', now()->endOfDay())
            ->whereNotIn('course_id', $current->pluck('course_id'))
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->orderBy('starts_at')
            ->get()
            ->unique('course_id')
            ->values();

        $cadences = CourseCadence::forMany($current->pluck('course'));

        $rows = $current
            ->map(fn (CourseBlock $block) => [
                'block' => $block,
                'course' => $block->course,
                'courseUrl' => CourseResource::getUrl('edit', ['record' => $block->course_id]),
                'streams' => ($cadences[$block->course_id] ?? null)?->blockStreams($block->starts_at, $block->ends_at) ?? [],
            ])
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['course']->title))
            ->values();

        return [
            'rows' => $rows,
            'groupNames' => $this->groupNames($rows),
            'scheduleUrl' => ScheduleResource::getUrl('index'),
            'upcoming' => $upcoming,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function groupNames(Collection $rows): array
    {
        $ids = $rows->flatMap(fn (array $row) => array_column($row['streams'], 'group_id'))
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty() ? [] : Group::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
