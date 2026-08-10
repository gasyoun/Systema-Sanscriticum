<?php

declare(strict_types=1);

namespace App\Filament\Pages\CourseDesignAssets;

use App\Services\Design\CourseDesignAssetService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Шапка страницы «Дизайн курсов». Считает только по активным курсам — тот же
 * охват, что у бейджа навигации (см. CourseDesignAssetService::snapshot).
 */
class CourseDesignStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $s = app(CourseDesignAssetService::class)->snapshot();

        $percent = $s['courses'] > 0 ? (int) round($s['complete'] / $s['courses'] * 100) : 0;

        return [
            Stat::make('Курсы укомплектованы', "{$s['complete']} из {$s['courses']}")
                ->description("{$percent}% активных курсов")
                ->color($percent >= 80 ? 'success' : ($percent >= 50 ? 'warning' : 'danger')),

            Stat::make('Пустых слотов', (string) $s['missingSlots'])
                ->description('баннеров осталось сделать')
                ->color($s['missingSlots'] === 0 ? 'success' : 'warning'),

            Stat::make('Без исходника', (string) $s['withoutPsd'])
                ->description('слотов без ссылки и файла PSD')
                ->color($s['withoutPsd'] === 0 ? 'success' : 'warning'),

            Stat::make('Пропорция не совпала', (string) $s['ratioMismatch'])
                ->description('проверьте, тот ли файл в слоте')
                ->color($s['ratioMismatch'] === 0 ? 'success' : 'warning'),
        ];
    }
}
