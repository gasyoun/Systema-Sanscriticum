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
        // Время редактируется в админке (MarketingSetting); schedule() читается
        // на каждый schedule:run, поэтому смена подхватывается без деплоя.
        // Защитный фолбэк на 09:00 — чтобы битое значение не уронило schedule:run.
        $paymentTime = \App\Models\MarketingSetting::cached()?->payment_reminder_time;
        $paymentTime = preg_match('/^\d{1,2}:\d{2}$/', (string) $paymentTime) ? $paymentTime : '09:00';
        $schedule->command('promises:remind-tomorrow')
            ->dailyAt($paymentTime);

        // Авто-напоминания должникам (просрочка / не продлил / блок за N дней до
        // начала). Гейт, окно, каналы и шаблон — внутри команды (MarketingSetting),
        // дедуп по cadence. Тот же утренний слот, что и напоминание об оплатах.
        $schedule->command('debts:remind')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-debtors');

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

        // Сгорание (decay) тратимой праны у давно неактивных студентов — еженедельно,
        // в ночное окно. Команда сама пропускает прогон, если decay выключен
        // (config prana.decay.enabled=false, дефолт), так что повесить безопасно:
        // включение делается флагом PRANA_DECAY_ENABLED без правки расписания.
        $schedule->command('prana:decay')
            ->weeklyOn(1, '04:00') // понедельник 04:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('prana-decay');

        // Ежемесячный пост «сейчас идут курсы» в ВК/ТГ (через n8n-вебхук).
        $schedule->command('schedule:post-monthly')
            ->monthlyOn(1, '10:00') // 1-е число месяца, 10:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('post-monthly-schedule');

        // --- ТРЕКИНГ АКТИВНОСТИ ---
        // Закрываем сессии, у которых нет heartbeat > 15 минут
        $schedule->job(new \App\Jobs\CloseStaleSessionsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping(10)         // защита от двойного запуска (если прошлый ещё не завершился)
            ->onOneServer()                  // если когда-то будет несколько серверов — запускать на одном
            ->name('close-stale-sessions');  // имя для логов и блокировки

        // --- ОБНОВЛЕНИЕ АВАТАРОК TG/VK ---
        // Раз в неделю освежаем аватарки тех, кого не синхронизировали 7+ дней
        // (или ни разу). Троттлинг внутри команды (--sleep) против rate-limit.
        $schedule->command('avatars:sync --apply --stale-days=7 --limit=300 --sleep=120')
            ->weeklyOn(2, '04:30') // вторник 04:30 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('sync-avatars');

        // --- УВЕДОМЛЕНИЕ ПРОПУСТИВШИМ ЗАНЯТИЕ (опт-ин) ---
        // Гейт и задержка — внутри команды (MarketingSetting), дедуп по
        // absent_notified_at. Окно проверяется часто, шлёт через N мин после конца.
        $schedule->command('classes:notify-absent')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('notify-absent-students');

        // --- СВЕРКА ПОСЕЩАЕМОСТИ ZOOM (страховка вебхука) ---
        // Ночью догружаем участников прошедших занятий через Reports API — на
        // случай пропущенных participant-вебхуков. Реалтайм покрывает вебхук.
        $schedule->command('zoom:sync-attendance --days=2')
            ->dailyAt('04:15')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('sync-zoom-attendance');

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

        // Telegram support-account analytics. The command is a no-op unless
        // TELEGRAM_SUPPORT_ENABLED=true and Telegram Client API credentials exist.
        $schedule->command('telegram-support:sync')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('telegram-support-sync');
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
