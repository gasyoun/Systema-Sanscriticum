<?php

namespace App\Console;

use App\Jobs\CloseStaleSessionsJob;
use App\Jobs\PruneStaleVisitorPresencesJob;
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
            ->dailyAt('03:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('archives-cleanup');

        // Перевод просроченных promises в статус expired — ночью.
        $schedule->command('promises:expire')
            ->dailyAt('03:30')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('promises-expire');

        // Сканирование ящика на предмет hard bounce (H1449 A3) — суппрессия
        // адреса на будущее. Не пишет ничего пока mail.bounce_scan.enabled=false.
        // withoutOverlapping — второй эшелон (замок живёт в кеше, CACHE_DRIVER=redis,
        // и на сбойном Redis не сработает); первый — imap_timeout() внутри команды
        // (ScanBounces::scanMailbox) плюс внешний flock/timeout вокруг schedule:run.
        $schedule->command('mail:scan-bounces')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('mail-scan-bounces');

        // Пересчёт авто-флага «неблагонадёжный» — после promises:expire,
        // чтобы вновь просроченные обещания сразу учитывались в пороге.
        $schedule->command('unreliable:recount')
            ->dailyAt('03:45')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('unreliable-recount');

        // Контроль дебиторки/рассрочки (H257): после promises:expire, чтобы
        // свежая просрочка уже учтена в пороге. Алерт финдиру при превышении —
        // замена ручного мониторинга владельца. Гейт «есть получатели» — внутри.
        $schedule->command('receivables:check')
            ->dailyAt('04:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('receivables-threshold-check');

        // Дежурный по файловому хранилищу (H1345): после archives:cleanup (03:00)
        // и backup:clean, чтобы мерить УЖЕ освобождённое место, а не временный
        // пик. Алерт админам при выходе за пороги config/storage_watch.php —
        // до этой команды рост медиа не измерялся ничем. Гейт «есть
        // получатели» — внутри команды.
        $schedule->command('storage:check')
            ->dailyAt('04:20')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('storage-usage-check');

        // Недельный KPI-дайджест делегирования (H259, фаза D): сводка всех фаз
        // финдиру по понедельникам утром — «ритм обзора» с зубами. Гейт «есть
        // получатели» — внутри команды.
        $schedule->command('finance:kpi-digest')
            ->weeklyOn(1, '09:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('finance-kpi-digest');

        // Недельная сводка преподавателю по домашкам, которые за него проверяет
        // проверяющий по гранту (H1729) — та же понедельничная утренняя рамка.
        // Гейты (фича включена, сводка включена, есть что показать) — в команде.
        $schedule->command('homework:reviewer-digest')
            ->weeklyOn(
                (int) config('homework.reviewers.digest_day', 1),
                (string) config('homework.reviewers.digest_time', '09:00'),
            )
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('homework-reviewer-digest');

        // Еженедельный goal check-in (H376): фиксирует темп каждой активной
        // цели и шлёт дайджест при отставании — та же понедельничная утренняя
        // рамка, что и KPI-дайджест.
        $schedule->command('goals:record-checkins')
            ->weeklyOn(1, '09:15')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('goals-record-checkins');

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
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('promises-remind-tomorrow');

        // Авто-напоминания должникам (просрочка / не продлил / блок за N дней до
        // начала). Гейт, окно, каналы и шаблон — внутри команды (MarketingSetting),
        // дедуп по cadence. Тот же утренний слот, что и напоминание об оплатах.
        $schedule->command('debts:remind')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-debtors');

        // Уведомление о недоборе группы за N дней до плановой даты старта
        // (recruitment_notify_lead_days, дефолт 2) — тот же утренний слот (H162).
        $schedule->command('groups:notify-forming-shortfall')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('notify-forming-shortfall');

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

        // VK/ORS content calendar auto-pilot (H1568, Wave 5): hourly tick
        // posts every due `scheduled` slot via n8n. No-op while
        // features.content_calendar_autopilot is OFF (default).
        $schedule->command('content:publish-due')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('publish-due-content-calendar');

        // Автооткрытие приёма ДЗ после проведённого урока (H1764, волна 1).
        // Ежечасный, а не ежедневный: момент открытия посчитан точно, проход
        // лишь доносит его с задержкой не больше часа. Прод-инертна, пока
        // homework.auto_open.course_slugs пуст (значение по умолчанию).
        $schedule->command('homework:auto-open')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('auto-open-homework');

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

        // --- ЛИД-МАГНИТ ЗА N МИНУТ ДО ВЕБИНАРА ---
        // Окно проверяется внутри команды (isMagnetWindowOpen у лендинга).
        $schedule->command('magnets:deliver-due')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-due-lead-magnets');

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

        // --- ПИСЬМО ЛИДАМ СО ССЫЛКОЙ НА ЗАПИСЬ ВЕБИНАРА ---
        // Триггер — админ заполнил webinar_recording_url; команда сама отсечёт уже отправленных.
        $schedule->command('webinar:deliver-recordings')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('deliver-webinar-recordings');

        // Планировщик анонсов (H816 PR 2): рассылает запланированные анонсы,
        // у которых наступил scheduled_at. Дедуп по dispatched_at внутри
        // диспетчера — no-op, если запланированных «на сейчас» анонсов нет.
        $schedule->command('announcements:dispatch-due')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('dispatch-due-announcements');

        // Telegram support-account analytics. The command is a no-op unless
        // TELEGRAM_SUPPORT_ENABLED=true and Telegram Client API credentials exist.
        //
        // TTL замка ВЫВОДИТСЯ из ГАРАНТИРОВАННОГО потолка жизни процесса, а не
        // задан числом и не выведен из watchdog-таймаута команды —
        // см. madelineSessionLockMinutes().
        $syncLockMinutes = $this->madelineSessionLockMinutes(
            (int) config('services.telegram_support.sync_timeout_seconds', 120),
        );
        $schedule->command('telegram-support:sync')
            ->everyMinute()
            ->withoutOverlapping($syncLockMinutes)
            ->onOneServer()
            ->name('telegram-support-sync');

        // W3.1 healthcheck (H595): алерт админам, если синк протух или
        // последний проход упал ошибкой — не чаще раза в 15 мин, no-op при
        // отсутствии включённых аккаунтов.
        $schedule->command('telegram-support:healthcheck')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('telegram-support-healthcheck');

        // D9 (Track C): раз в час юзербот снимает ростер каждой учебной группы с
        // telegram_chat_id → «Состав чата» на дашборде «Записи (бот)» заполняется
        // сам. Редкий слот: держит общий замок сессии на весь проход, ежеминутный
        // telegram-support:sync это переживёт (деградирует в session_busy → повтор).
        // No-op при выключенном харвесте/support или неконфигурной MadelineProto.
        // TTL замка считается тем же madelineSessionLockMinutes(), что и у
        // telegram-support:sync выше: сессия у них одна, а значит и граница
        // «замок переживает держателя» должна быть одна.
        $rosterLockMinutes = $this->madelineSessionLockMinutes(
            (int) config('services.telegram_harvest.roster_timeout_seconds', 600),
        );
        $schedule->command('telegram-harvest:roster-groups')
            ->hourly()
            ->withoutOverlapping($rosterLockMinutes)
            ->onOneServer()
            ->name('telegram-harvest-roster-groups');

        // Track C (H164): @zapisi_ORSbot напоминает о занятии в чат группы прямо
        // из расписания (Schedule → group.telegram_chat_id). No-op, пока не включён
        // features.telegram_zapisi_bot; окно и дедуп (zapisi_reminded_at) — внутри команды.
        $schedule->command('zapisi:remind-classes')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('zapisi-remind-classes');

        // --- РАЗОВЫЕ НАПОМИНАНИЯ СТУДЕНТАМ (ScheduledReminder) ---
        // Куратор ставит текст + дату один раз в карточке студента (кнопка
        // «Запланировать напоминание») — дальше это дело системы, не человека.
        $schedule->command('reminders:send-due')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('send-due-reminders');

        // --- H987 RQ4 STUDY: 4-НЕДЕЛЬНОЕ НАПОМИНАНИЕ (features.rq4_study, ВЫКЛ по умолч.) ---
        $schedule->command('rq4:send-retention-reminders')
            ->dailyAt('09:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('rq4-retention-reminders');

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

        // --- WEEKLY DB + FILE STORAGE BACKUP (spatie/laravel-backup) ---
        // H364: source.files.include now covers storage/app (uploads, finance
        // templates, imports, lectures) alongside the DB dump, to local + yandex_disk
        // (config/backup.php). Until YANDEX_DISK_LOGIN/YANDEX_DISK_APP_PASSWORD
        // are set in .env, the yandex_disk write just fails — local still lands.
        $schedule->command('backup:run')
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

        // --- ДНЕВНЫЕ ROLLUP'Ы ВЕБ-СТОРОНЫ (H1837, тикет S10) ---
        // TG-сторона агрегируется побочным эффектом telegram-support:sync; у веба
        // синка нет, поэтому отдельный проход. Почасовой, а не ночной: KPI
        // «висит без ответа N часов» должен дозревать в течение дня, а не через
        // сутки. Окно перекрытия (по умолчанию 2 дня) — внутри команды. Гейт
        // features.support_web_rollups — тоже внутри (пока OFF, это no-op).
        $schedule->command('support:rollup-web')
            ->hourlyAt(25)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('support-rollup-web');

        // Просроченные (14+ дней) pending-черновики FAQ-ответов → expired.
        $schedule->command('support:expire-stale-answer-suggestions')
            ->dailyAt('04:10')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expire-stale-support-answer-suggestions');

        // --- РИДЕР БРОШЕННЫХ ЧЕКАУТОВ (H1358) ---
        // Заваливает (failed) зависшие pending-платежи старше checkout.legacy_pending_days
        // (или webhook-буфера timed промо-брони), освобождая прану/реферальный
        // кредит/депозит/промо-слот через Payment::booted(). Deposit/trial/paypal/
        // conditional строки не трогает никогда. Частый слот (деньги, не «раз в сутки»
        // дебри) с построчным row-lock против гонки с банковским вебхуком. Без --apply
        // команда только отчитывается — гейт features.checkout_stale_order_expiry
        // проверяется только когда --apply передан.
        $schedule->command('payments:expire-stale-checkouts --apply')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expire-stale-checkouts');

        // --- ПУЛЬС ПЛАНИРОВЩИКА (H1713) ---
        // Дёргает уникальный URL на healthchecks.io; тревогу поднимает МОЛЧАНИЕ,
        // а не ошибка, поэтому сторож переживает смерть всего сервера — в
        // отличие от любой проверки, живущей на самой машине (простой #730 длился
        // двое суток именно поэтому). Заодно проверяет Horizon: «сайт отвечает, а
        // очереди стоят» внешний монитор доступности увидеть не может.
        //
        // evenInMaintenanceMode: на время выкладки пульс не должен пропадать,
        // иначе каждый деплой = ложная тревога. onOneServer НЕ ставим сознательно:
        // пульс обязан идти, даже если Redis-лок недоступен, — а именно лежащий
        // Redis и есть один из отслеживаемых отказов.
        //
        // Пусто в HEARTBEAT_PING_URL → команда ничего не шлёт (fail-open).
        $schedule->command('heartbeat:ping')
            ->cron((string) config('heartbeat.cron', '*/5 * * * *'))
            ->withoutOverlapping(5)
            ->evenInMaintenanceMode()
            ->name('scheduler-heartbeat');

        // --- ДАЙДЖЕСТ CSRF-НЕСОВПАДЕНИЙ (H1773) ---
        // Ежедневно, окно по умолчанию — 1 сутки (config/csrf.php): та же
        // частота, что у receivables:check/storage:check. Алерт админам —
        // только при превышении порога; гейт «есть получатели» — внутри
        // команды.
        $schedule->command('csrf:mismatch-digest')
            ->dailyAt('04:25')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('csrf-mismatch-digest');

        // --- ПУЛЬС КАБИНЕТА (H1777) ---
        // Homepage-uptime и heartbeat:ping не видят «/ отвечает 200, а /dvaram
        // 500 / Auth сломан / Filament не пускает менеджера». Login smoke-
        // менеджера (TEST_MANAGER_*) + GET ключевых поверхностей in-process.
        // evenInMaintenanceMode: выкладка не должна глушить сторож.
        // onOneServer НЕ ставим: как у heartbeat, Redis-лок не должен гасить
        // проверку (лежащий Redis — соседний сигнал, не причина молчать).
        // Без TEST_MANAGER_PASSWORD — no-op SUCCESS (fail-open).
        $schedule->command('cabinet:probe')
            ->cron((string) config('cabinet_probe.cron', '*/15 * * * *'))
            ->withoutOverlapping(10)
            ->evenInMaintenanceMode()
            ->name('cabinet-health-probe');
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
