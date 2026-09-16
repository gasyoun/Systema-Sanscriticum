<?php

namespace App\Console\Concerns;

use App\Models\Season;
use Illuminate\Console\Scheduling\Schedule;

trait SchedulesStudentsAndContent
{
    /** class reminders, DST alerts, group links, onboarding, care, prana. */
    private function scheduleStudentReminders(Schedule $schedule): void
    {
        // Напоминание студентам о скором занятии (за ~60 мин до старта, по Schedule).
        // Окно и дедуп — внутри команды (reminded_at).
        $schedule->command('classes:remind-upcoming')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-upcoming-classes');

        // H4434 — DST-будильник нон-МСК ученикам (T−7д, T−1д вечером, T−1ч).
        // Ежедневный скан: юзеров с явной зоной ~десятки, дедуп в tz_alerts_sent.
        $schedule->command('tz:remind-dst-shifts')
            ->dailyAt('10:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-dst-shifts');

        // Авто-постинг ссылки на занятие в Telegram-чат группы (за ~15 мин до
        // старта, ОДНО сообщение на группу — в отличие от remind-upcoming, что
        // шлёт персональные ЛС). Гейт (class_link_autopost_enabled), окно и дедуп
        // (group_link_posted_at) — внутри команды; без telegram_chat_id у группы — no-op.
        $schedule->command('classes:post-group-link')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('post-class-link-to-group');

        // Еженедельная сводка в чат онбординга: % с доступом, кто ни разу не заходил.
        $schedule->command('onboarding:weekly-digest')
            ->weeklyOn(1, '09:30') // понедельник 09:30 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('onboarding-weekly-digest');

        // Еженедельный автонапоминание тем же не заходившим: Telegram → VK → SMS
        // → email (см. SendCabinetInvites). Батч 50/неделю — не спам-флаги, не
        // блокировать очередь; --resend не передаём, каждый получает ровно один
        // повторный призыв, пока не пришёл (cabinet_invite_sent_at дедупит).
        $schedule->command('students:send-login-invites --send --limit=50')
            ->weeklyOn(1, '10:00') // понедельник 10:00 МСК, после дайджеста
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('send-login-invites');

        // H4392 (MG 08-09-2026): «Кто на чём закончил» — еженедельный пост в чат
        // «Институт» (TELEGRAM_INSTITUTE_CHAT_ID; флаг WEEKLY_FINISH_REPORT_ENABLED).
        // Понедельник 10:30 МСК, после приглашений; команда сама гейтится флагом,
        // без него — тихий выход.
        $schedule->command('care:weekly-finish')
            ->weeklyOn(1, '10:30') // понедельник 10:30 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('care-weekly-finish');

        // Сгорание (decay) тратимой праны у давно неактивных студентов — еженедельно,
        // в ночное окно. Команда сама пропускает прогон, если decay выключен
        // (config prana.decay.enabled=false, дефолт), так что повесить безопасно:
        // включение делается флагом PRANA_DECAY_ENABLED без правки расписания.
        $schedule->command('prana:decay')
            ->weeklyOn(1, '04:00') // понедельник 04:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('prana-decay');

        // H4966: ежедневный монитор протухшего пина пробного занятия
        // (Course.trial_schedule_id в прошлом) — алерт админам в Telegram.
        $schedule->command('trial:check-freshness')
            ->dailyAt('09:00') // 09:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('trial-check-freshness');

    }

    /** Season 1 open/notify/close cron + leaderboard refresh. */
    private function scheduleSeasonOne(Schedule $schedule): void
    {
        // Сезон 1: старт 01.09.2026 00:00 MSK (UTC+3 → UTC 21:00 31.08)
        $schedule->command('season:open 1')
            ->cron('0 21 31 8 *')
            ->onOneServer()
            ->name('season-1-open');

        // Сезон 1: уведомление студентам о старте — T-24h (30-08 21:00 UTC).
        // Идемпотентно (season_notifications) и безопасно при выключенном
        // SEASON1_NOTIFY_ENABLED: без флага живая отправка не выполняется.
        $schedule->command('season:notify-start 1')
            ->cron('0 21 30 8 *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('season-1-notify-start');

        // Сезон 1: закрытие 01.01.2027 00:00 MSK (UTC 21:00 31.12.2026)
        $schedule->command('season:close 1')
            ->cron('0 21 31 12 *')
            ->onOneServer()
            ->name('season-1-close');

        // Пересчёт лидерборда каждые 4 часа в период сезона
        $schedule->command('season:refresh-leaderboard')
            ->everyFourHours()
            ->when(fn () => Season::isActive())
            ->onOneServer()
            ->name('season-leaderboard-refresh');

    }

    /** schedule posts, content calendar, story queues, homework auto-open. */
    private function schedulePublishing(Schedule $schedule): void
    {
        // Ежемесячный пост «сейчас идут курсы» в ВК/ТГ (через n8n-вебхук).
        $schedule->command('schedule:post-monthly')
            ->monthlyOn(1, '10:00') // 1-е число месяца, 10:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('post-monthly-schedule');

        // Полный пост расписания курса в чаты обучения (H4328): ежедневный
        // свип забирает группы с расписанием, менявшимся за сутки (перенос,
        // ручная правка, перегенерация, удаление) и шлёт пост ТОЛЬКО при
        // смене текста (hash в schedule_posts). Переносы одного дня
        // схлопываются в один пост следующего дня — решение MG 07-09-2026.
        // Без флага SCHEDULE_FULL_POST команда no-op.
        $schedule->command('courses:post-schedule --due')
            ->dailyAt('10:00') // 10:00 МСК
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('post-course-full-schedule-sweep');

        // VK/ORS content calendar auto-pilot (H1568, Wave 5): hourly tick
        // posts every due `scheduled` slot via n8n. No-op while
        // features.content_calendar_autopilot is OFF (default).
        $schedule->command('content:publish-due')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('publish-due-content-calendar');

        // Канал @rusamskrtam: автопилот очереди story_posts (H3930, Phase 1).
        // Ежечасный, как content:publish-due: точность слота «09:00/19:00»
        // важнее редкости. Прод-инертен, пока features.telegram_story_publisher
        // OFF (default); photo/video строки скипаются с журналом до Phase 2.
        $schedule->command('stories:publish-due')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('publish-due-story-posts');

        // Персона @rusamskrtam: user-сториз через MadelineProto (H3964, Phase 2).
        // Тик рядом со stories:publish-due. Прод-инертен, пока
        // features.telegram_story_stories OFF (default). Открывает ЕДИНУЮ
        // MadelineProto-сессию → TTL лока выводится тем же
        // madelineSessionLockMinutes(), что у support/harvest; пропуск из-за
        // занятой сессии — норма (session_busy), повтор через час.
        $schedule->command('stories:publish-story')
            ->hourly()
            ->withoutOverlapping($this->madelineSessionLockMinutes(
                (int) config('services.telegram_story.stories_timeout_seconds', 120),
            ))
            ->onOneServer()
            ->name('publish-story-persona');

        // Автооткрытие приёма ДЗ после проведённого урока (H1764, волна 1).
        // Ежечасный, а не ежедневный: момент открытия посчитан точно, проход
        // лишь доносит его с задержкой не больше часа. Прод-инертна, пока
        // homework.auto_open.course_slugs пуст (значение по умолчанию).
        $schedule->command('homework:auto-open')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('auto-open-homework');

    }
}
