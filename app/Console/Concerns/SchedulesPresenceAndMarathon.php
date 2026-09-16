<?php

namespace App\Console\Concerns;

use App\Jobs\CloseStaleSessionsJob;
use App\Jobs\PruneStaleVisitorPresencesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

trait SchedulesPresenceAndMarathon
{
    /** activity jobs, avatar sync, absent notices, Zoom sync, lead magnets. */
    private function schedulePresenceAndSync(Schedule $schedule): void
    {
        // --- ТРЕКИНГ АКТИВНОСТИ ---
        // Закрываем сессии, у которых нет heartbeat > 15 минут
        $schedule->job(new CloseStaleSessionsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping(10)         // защита от двойного запуска (если прошлый ещё не завершился)
            ->onOneServer()                  // если когда-то будет несколько серверов — запускать на одном
            ->name('close-stale-sessions');  // имя для логов и блокировки

        // Вымести устаревшие строки присутствия посетителей (H1197, Jivo-паритет
        // Pillar 2): старше support_presence.prune_after_minutes. Эфемерная таблица —
        // персональные данные анонимного посетителя не залёживаются (152-ФЗ). Гейта
        // по флагу тут нет: при выключенном presence таблица пуста → джоба — no-op.
        $schedule->job(new PruneStaleVisitorPresencesJob)
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('prune-stale-visitor-presences');

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

        // H3247: пробные Deal после Zoom-сверки. Гейт внутри команды.
        $schedule->command('crm:reconcile-trial-attendance')
            ->dailyAt('04:18')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('reconcile-trial-attendance');

        // --- ЛИД-МАГНИТ ЗА N МИНУТ ДО ВЕБИНАРА ---
        // Окно проверяется внутри команды (isMagnetWindowOpen у лендинга).
        $schedule->command('magnets:deliver-due')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-due-lead-magnets');

    }

    /** marathon content/recording/warm-tail/channel posts + webinar recordings. */
    private function scheduleMarathon(Schedule $schedule): void
    {
        // --- МАРАФОН: DAY 1/2/3 КОНТЕНТ ПО ЛИЧНОМУ ДНЮ (H440/H464/H487) ---
        // currentDay() считается от day0_started_at энрола, НЕ от общего календаря.
        $schedule->command('marathon:deliver-due')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-due-marathon-content');

        // --- МАРАФОН: ЗАПИСЬ ЖИВОЙ КОНСУЛЬТАЦИИ ДНЯ 3 (H487) ---
        // Триггер — MG проставил Schedule.zoom_recording_url; не таймер от start.
        $schedule->command('marathon:deliver-recording')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-marathon-recording');

        // --- МАРАФОН: ТЁПЛЫЙ ХВОСТ ДНИ 4-16 (H440 Phase 6) ---
        // Только неоплатившие (paid_at null); идемпотентность — warm_tail_last_day_sent.
        $schedule->command('marathon:deliver-warm-tail')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-marathon-warm-tail');

        // --- МАРАФОН: ПОСТЫ В КАНАЛ @samskrte (H1067/H1936) ---
        // Требует: magnet-бот администратор канала с правом Post Messages (Telegram-side,
        // делается вручную в приложении). Идемпотентность — marathon_channel_posts_sent.
        $schedule->command('marathon:publish-channel-posts --post=1 --live')
            ->cron('0 10 14 8 *')->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('marathon-channel-post-1-announce');

        // G31 / H3204 — start-post cron + when() both read marathon.launch_date
        // so a later 28 August cannot re-publish the cohort-zero «день старта».
        $launchDate = (string) config('marathon.launch_date', '2026-08-28');
        $launch = Carbon::parse($launchDate, 'Europe/Moscow');
        $schedule->command('marathon:publish-channel-posts --post=2 --live')
            ->cron(sprintf('0 10 %d %d *', $launch->day, $launch->month))
            ->timezone('Europe/Moscow')
            ->when(fn () => now('Europe/Moscow')->toDateString() === $launchDate)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('marathon-channel-post-2-start');

        // Evergreen, weekly, starting after the cohort is live (~1 week post-start).
        $schedule->command('marathon:publish-channel-posts --post=3 --live')
            ->weeklyOn(1, '10:00')->timezone('Europe/Moscow')
            ->when(fn () => now('Europe/Moscow')->greaterThanOrEqualTo(
                Carbon::parse('2026-09-04', 'Europe/Moscow')
            ))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('marathon-channel-post-3-evergreen');

        // --- ПИСЬМО ЛИДАМ СО ССЫЛКОЙ НА ЗАПИСЬ ВЕБИНАРА ---
        // Триггер — админ заполнил webinar_recording_url; команда сама отсечёт уже отправленных.
        $schedule->command('webinar:deliver-recordings')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-webinar-recordings');

    }

    /** scheduled announcement dispatcher. */
    private function scheduleAnnouncements(Schedule $schedule): void
    {
        // Планировщик анонсов (H816 PR 2): рассылает запланированные анонсы,
        // у которых наступил scheduled_at. Дедуп по dispatched_at внутри
        // диспетчера — no-op, если запланированных «на сейчас» анонсов нет.
        $schedule->command('announcements:dispatch-due')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('dispatch-due-announcements');

    }
}
