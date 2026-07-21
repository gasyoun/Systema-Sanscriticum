<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\Teacher;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Widgets\ChartWidget;

/**
 * Стековая столбчатая диаграмма «нагрузка по направлениям» (H1426): один
 * teacher = один столбец, каждая серия = направление (Category), высота
 * сегмента = число групп этого преподавателя в этом направлении.
 */
class TeacherLoadByDirectionChart extends ChartWidget
{
    protected static ?string $heading = 'Нагрузка по направлениям';

    protected static ?int $sort = 1;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    protected function getData(): array
    {
        $teachers = Teacher::query()->orderBy('name')->get();
        $directions = Category::query()->orderBy('sort_order')->get();

        $palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

        $datasets = $directions->values()->map(function (Category $direction, int $index) use ($teachers, $palette) {
            $data = $teachers->map(
                fn (Teacher $teacher) => $teacher->groupsLed()
                    ->filter(fn ($group) => $group->directions()->contains('id', $direction->id))
                    ->count()
            )->all();

            return [
                'label' => $direction->name,
                'data' => $data,
                'backgroundColor' => $palette[$index % count($palette)],
            ];
        })->all();

        return [
            'datasets' => $datasets,
            'labels' => $teachers->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
