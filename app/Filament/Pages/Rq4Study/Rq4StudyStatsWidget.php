<?php

declare(strict_types=1);

namespace App\Filament\Pages\Rq4Study;

use App\Models\Rq4Participant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * H1005 -- RQ4 study enrollment stats, admin-only. Built so a human without
 * SSH to the production server (MG doesn't have it -- only the deploy
 * contractor does, per docs/deploy.md) can still check enrollment numbers
 * themselves via the admin panel, no artisan command needed.
 */
class Rq4StudyStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = Rq4Participant::count();
        $armA = Rq4Participant::where('arm', Rq4Participant::ARM_ON_RAMP)->count();
        $armB = Rq4Participant::where('arm', Rq4Participant::ARM_TALMUD)->count();

        $preTestDone = Rq4Participant::whereNotNull('pre_test_completed_at')->count();
        $postTestDone = Rq4Participant::whereNotNull('post_test_completed_at')->count();
        $retentionDone = Rq4Participant::whereNotNull('retention_test_completed_at')->count();

        $retentionDueNow = Rq4Participant::query()->retentionReminderDue()->count();
        $retentionAwaited = Rq4Participant::query()
            ->whereNotNull('retention_due_at')
            ->whereNull('retention_test_completed_at')
            ->count();

        return [
            Stat::make('Записалось всего', (string) $total)
                ->description("Группа A (on-ramp): {$armA} · Группа B (Талмуд): {$armB}")
                ->color('primary'),
            Stat::make('Прошли 1-й тест', (string) $preTestDone)
                ->description($total > 0 ? round(100 * $preTestDone / $total).'% от записавшихся' : '—')
                ->color('info'),
            Stat::make('Прошли 2-й тест', (string) $postTestDone)
                ->description($preTestDone > 0 ? round(100 * $postTestDone / $preTestDone).'% от прошедших 1-й' : '—')
                ->color('info'),
            Stat::make('Прошли тест через 4 недели', (string) $retentionDone)
                ->description($retentionAwaited > 0 ? "{$retentionDone} из ".($retentionDone + $retentionAwaited).' ожидаемых' : 'пока никто не должен')
                ->color($retentionDone > 0 ? 'success' : 'gray'),
            Stat::make('Ожидают напоминания сейчас', (string) $retentionDueNow)
                ->description('срок настал, напоминание ещё не отправлено')
                ->color($retentionDueNow > 0 ? 'warning' : 'success'),
        ];
    }
}
