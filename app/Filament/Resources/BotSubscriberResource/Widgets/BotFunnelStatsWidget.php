<?php

declare(strict_types=1);

namespace App\Filament\Resources\BotSubscriberResource\Widgets;

use App\Services\OpenLessonCards\OpenLessonFunnelReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Воронка в цифрах: аудитория → «Участвовать» → подписка → анкета → лид. */
class BotFunnelStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getStats(): array
    {
        $report = app(OpenLessonFunnelReport::class);
        $audience = $report->audience();

        return array_map(function (array $step) use ($audience): Stat {
            $description = $step['key'] === 'audience'
                ? "/start: {$audience['started']} · заблокировали: {$audience['blocked']}"
                : "{$step['percent']}% от предыдущего шага";

            return Stat::make($step['label'], (string) $step['count'])
                ->description($description)
                ->color($step['key'] === 'lead' ? 'success' : 'gray');
        }, $report->steps());
    }
}
