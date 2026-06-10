<?php

declare(strict_types=1);

namespace App\Filament\Pages\LessonMaterials;

use App\Support\LessonMaterialsReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LessonMaterialsStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $r = LessonMaterialsReport::overall();

        return [
            Stat::make('Уроки с материалами', "{$r['withMaterials']} из {$r['total']}")
                ->description("{$r['percent']}% покрытия")
                ->color($r['percent'] >= 80 ? 'success' : ($r['percent'] >= 50 ? 'warning' : 'danger')),
            Stat::make('Видео', (string) $r['video']),
            Stat::make('Вложения', (string) $r['attachments']),
            Stat::make('Транскрипт', (string) $r['transcript']),
            Stat::make('Домашка', (string) $r['homework']),
        ];
    }
}
