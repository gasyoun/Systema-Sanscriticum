<?php

namespace App\Console;

use App\Console\Concerns\SchedulesOpsAndMembership;
use App\Console\Concerns\SchedulesOvernightAndCrm;
use App\Console\Concerns\SchedulesPresenceAndMarathon;
use App\Console\Concerns\SchedulesStudentsAndContent;
use App\Console\Concerns\SchedulesSupportAndPayments;
use App\Console\Concerns\SchedulesTelegram;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    use SchedulesOpsAndMembership;
    use SchedulesOvernightAndCrm;
    use SchedulesPresenceAndMarathon;
    use SchedulesStudentsAndContent;
    use SchedulesSupportAndPayments;
    use SchedulesTelegram;

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Domain groups in original registration order - pure move (H4517).
        $this->scheduleOvernightMaintenance($schedule);
        $this->scheduleWeeklyDigests($schedule);
        $this->scheduleCrmAndLifecycle($schedule);
        $this->scheduleRecordingGaps($schedule);
        $this->scheduleSupportAndKnowledge($schedule);
        $this->schedulePaymentReminders($schedule);
        $this->scheduleStudentReminders($schedule);
        $this->scheduleSeasonOne($schedule);
        $this->schedulePublishing($schedule);
        $this->schedulePresenceAndSync($schedule);
        $this->scheduleMarathon($schedule);
        $this->scheduleAnnouncements($schedule);
        $this->scheduleTelegramSupport($schedule);
        $this->scheduleTelegramHarvest($schedule);
        $this->scheduleZapisiAndReminders($schedule);
        $this->scheduleBackups($schedule);
        $this->scheduleFaqAndCheckout($schedule);
        $this->scheduleWatchdogs($schedule);
        $this->scheduleMembershipAndPaypal($schedule);
    }

    /**
     * TTL замка ->withoutOverlapping() для команд, работающих на ОДНОЙ общей
     * MTProto-сессии (telegram-support:sync, telegram-harvest:roster-groups).
     *
     * Задача одна: замок обязан ПЕРЕЖИТЬ своего держателя. Laravel снимает его по
     * истечении TTL, даже если процесс ещё жив, и тогда на одной сессии
     * оказываются два экземпляра — это `AUTH_RESTART` на живом аккаунте
     * поддержки, а 27.07.2026 это дало десять параллельных синков и EMFILE.
     *
     * ЧЕМ ЭТО СЧИТАЕТСЯ И ПОЧЕМУ НЕ watchdog-таймаутом. Прежняя версия выводила
     * TTL из `sync_timeout_seconds` (120 с → 7 мин) на основании «пока таймаут <
     * TTL, зависший заход умирает первым». 28.07.2026 инвариант не выполнился:
     * заход прожил 10 470 с при потолке 120 с, то есть замок протух за это время
     * двадцать пять раз (разбор — H1915, {@see MadelineSyncWatchdog}). Watchdog
     * с тех пор чинен и снова надёжен, но выводить границу из него нельзя:
     * без расширения pcntl он честный no-op, и тогда единственной оградой
     * остаётся внешняя обёртка. Поэтому берётся ГАРАНТИРОВАННАЯ верхняя граница
     * жизни процесса — та, что держится в любом случае:
     *
     *   systema-schedule-run.sh снимает заход по `timeout` на SCHEDULE_MAX_SECONDS,
     *   а пережившего это straggler'а добивает reaper на следующем проходе —
     *   `kill -KILL` при возрасте > 2x потолка. Значит дольше 2x не живёт никто.
     *
     * При 900 с это 35 мин против прежних 7. Цена — заход, убитый жёстко (без
     * своей уборки), задерживает синк до получаса; выигрыш — двух экземпляров на
     * одной сессии не бывает НИКОГДА. Для минутной команды это правильный размен:
     * пауза видна healthcheck'у, а AUTH_RESTART роняет живой аккаунт.
     */
    private function madelineSessionLockMinutes(int $watchdogTimeoutSeconds): int
    {
        $hardCeiling = max(
            $watchdogTimeoutSeconds,
            ((int) config('schedule_guard.max_seconds', 900)) * 2,
        );

        return (int) ceil($hardCeiling / 60) + 5;
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
