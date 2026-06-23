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

        // Перевод просроченных promises в статус expired — ночью.
        $schedule->command('promises:expire')
            ->dailyAt('03:30');

        // Пересчёт авто-флага «неблагонадёжный» — после promises:expire,
        // чтобы вновь просроченные обещания сразу учитывались в пороге.
        $schedule->command('unreliable:recount')
            ->dailyAt('03:45');

        // Напоминание студенту: завтра срок оплаты по обещанию/рассрочке.
        $schedule->command('promises:remind-tomorrow')
            ->dailyAt('09:00');

        // Напоминание студентам о скором занятии (за ~60 мин до старта, по Schedule).
        // Окно и дедуп — внутри команды (reminded_at).
        $schedule->command('classes:remind-upcoming')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-upcoming-classes');

        // Еженедельная сводка в чат онбординга: % с доступом, кто ни разу не заходил.
        $schedule->command('onboarding:weekly-digest')
            ->weeklyOn(1, '09:30'); // понедельник 09:30 МСК

        // --- ТРЕКИНГ АКТИВНОСТИ ---
        // Закрываем сессии, у которых нет heartbeat > 15 минут
        $schedule->job(new \App\Jobs\CloseStaleSessionsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping(10)         // защита от двойного запуска (если прошлый ещё не завершился)
            ->onOneServer()                  // если когда-то будет несколько серверов — запускать на одном
            ->name('close-stale-sessions');  // имя для логов и блокировки

        // --- ЛИД-МАГНИТ ЗА N МИНУТ ДО ВЕБИНАРА ---
        // Окно проверяется внутри команды (isMagnetWindowOpen у лендинга).
        $schedule->command('magnets:deliver-due')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-due-lead-magnets');

        // --- ПИСЬМО ЛИДАМ СО ССЫЛКОЙ НА ЗАПИСЬ ВЕБИНАРА ---
        // Триггер — админ заполнил webinar_recording_url; команда сама отсечёт уже отправленных.
        $schedule->command('webinar:deliver-recordings')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-webinar-recordings');
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
