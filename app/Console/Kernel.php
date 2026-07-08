<?php

namespace App\Console;

use App\Jobs\CloseStaleSessionsJob;
use App\Models\MarketingSetting;
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

        // Контроль дебиторки/рассрочки (H257): после promises:expire, чтобы
        // свежая просрочка уже учтена в пороге. Алерт финдиру при превышении —
        // замена ручного мониторинга владельца. Гейт «есть получатели» — внутри.
        $schedule->command('receivables:check')
            ->dailyAt('04:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('receivables-threshold-check');

        // Недельный KPI-дайджест делегирования (H259, фаза D): сводка всех фаз
        // финдиру по понедельникам утром — «ритм обзора» с зубами. Гейт «есть
        // получатели» — внутри команды.
        $schedule->command('finance:kpi-digest')
            ->weeklyOn(1, '09:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('finance-kpi-digest');

        // Напоминание менеджеру о заявках с наступившим next_contact_at.
        // Гейт (crm_reminders) и дедуп (reminded_at) — внутри команды; пока
        // флаг выключен, прогон — no-op.
        $schedule->command('leads:remind-followup')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-leads-followup');

        // Напоминание студенту: завтра срок оплаты по обещанию/рассрочке.
        // Время редактируется в админке (MarketingSetting); schedule() читается
        // на каждый schedule:run, поэтому смена подхватывается без деплоя.
        // Защитный фолбэк на 09:00 — чтобы битое значение не уронило schedule:run.
        $paymentTime = MarketingSetting::cached()?->payment_reminder_time;
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
            ->weeklyOn(1, '09:30'); // понедельник 09:30 МСК

        // Еженедельный автонапоминание тем же не заходившим: Telegram → VK → SMS
        // → email (см. SendCabinetInvites). Батч 50/неделю — не спам-флаги, не
        // блокировать очередь; --resend не передаём, каждый получает ровно один
        // повторный призыв, пока не пришёл (cabinet_invite_sent_at дедупит).
        $schedule->command('students:send-login-invites --send --limit=50')
            ->weeklyOn(1, '10:00') // понедельник 10:00 МСК, после дайджеста
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('send-login-invites');

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
        $schedule->job(new CloseStaleSessionsJob)
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

        // --- РАЗОВЫЕ НАПОМИНАНИЯ СТУДЕНТАМ (ScheduledReminder) ---
        // Куратор ставит текст + дату один раз в карточке студента (кнопка
        // «Запланировать напоминание») — дальше это дело системы, не человека.
        $schedule->command('reminders:send-due')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('send-due-reminders');

        // --- ДЕТЕКТОР ПРОСЬБ «НАПОМНИТЕ МНЕ» В ПЕРЕПИСКЕ (H187) ---
        // Гибрид regex+LLM поверх веб-чата и импортированного TG-support; создаёт
        // только предложение (ReminderSuggestion) — ничего не отправляет сам.
        // Гейт reminder_detection_enabled (MarketingSetting) внутри сервиса.
        $schedule->command('reminders:detect-requests')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('detect-reminder-requests');

        // Просроченные (14+ дней без действия куратора) pending-предложения → expired.
        $schedule->command('reminders:expire-stale-suggestions')
            ->dailyAt('04:05')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expire-stale-reminder-suggestions');

        // --- WEEKLY DB BACKUP (spatie/laravel-backup) ---
        // DB-only dump (source.files.include is empty) to local + yandex_disk
        // (config/backup.php). Until YANDEX_DISK_LOGIN/YANDEX_DISK_APP_PASSWORD
        // are set in .env, the yandex_disk write just fails — local still lands.
        $schedule->command('backup:run --only-db')
            ->weeklyOn(1, '02:00') // Monday 02:00 MSK, ahead of other nightly jobs
            ->withoutOverlapping(60)
            ->onOneServer()
            ->name('weekly-backup-run');

        // Cleanup old archives per config/backup.php's strategy, right after the run.
        $schedule->command('backup:clean')
            ->weeklyOn(1, '02:30')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->name('weekly-backup-clean');

        // --- FAQ-СУГГЕСТЕР ОТВЕТОВ (H247, тикет S3) ---
        // Regex-префильтр поверх веб-чата и TG-support находит фактологические
        // вопросы (Zoom/записи/расписание) и собирает факт-черновик ответа из LMS —
        // БЕЗ LLM. Создаёт только предложение (SupportAnswerSuggestion), ничего не
        // шлёт. Гейт: config('features.support_answer_suggester') + админ-тумблер
        // support_answer_suggester_enabled (MarketingSetting) — оба внутри сервиса.
        $schedule->command('support:suggest-answers')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('suggest-support-answers');

        // Просроченные (14+ дней) pending-черновики FAQ-ответов → expired.
        $schedule->command('support:expire-stale-answer-suggestions')
            ->dailyAt('04:10')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expire-stale-support-answer-suggestions');
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
