<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CourseFavorite;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Widgets\ChartWidget;

/**
 * H5134 — «Направления по сердцам»: агрегат сердечек «Избранного» по
 * категории курса (направлению), топ-8. Карточки ждуна без карточки курса
 * (waitlist_slug-сердечки) и курсы без категории попадают в «Без направления».
 * Отдельный сигнал от голосов ждуна — WaitlistVote не учитывается.
 */
class FavoritesByDirectionChart extends ChartWidget
{
    protected static ?string $heading = 'Направления по сердцам';

    protected static ?int $sort = 2;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    protected function getData(): array
    {
        $byDirection = CourseFavorite::query()
            ->whereNotNull('course_favorites.course_id')
            ->join('category_course', 'category_course.course_id', '=', 'course_favorites.course_id')
            ->join('categories', 'categories.id', '=', 'category_course.category_id')
            ->selectRaw('categories.name as name, count(*) as hearts')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('hearts')
            ->limit(8)
            ->pluck('hearts', 'name');

        // Остальное одним бакетом: курсы без категории + waitlist-анонсы.
        $categorized = (int) $byDirection->sum();
        $total = CourseFavorite::count();
        if ($total > $categorized) {
            $byDirection['Без направления'] = $total - $categorized;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Сердца',
                    'data' => $byDirection->values()->all(),
                    'backgroundColor' => '#f43f5e',
                ],
            ],
            'labels' => $byDirection->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
