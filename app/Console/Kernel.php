<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('archives:cleanup --hours=24')
             ->dailyAt('03:00');
             
        // --- ТРЕКИНГ АКТИВНОСТИ ---
    // Закрываем сессии, у которых нет heartbeat > 15 минут
    $schedule->job(new \App\Jobs\CloseStaleSessionsJob())
        ->everyFiveMinutes()
        ->withoutOverlapping(10)         // защита от двойного запуска (если прошлый ещё не завершился)
        ->onOneServer()                  // если когда-то будет несколько серверов — запускать на одном
        ->name('close-stale-sessions');  // имя для логов и блокировки     
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
