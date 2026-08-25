<?php

namespace App\Console;

use App\Jobs\CloseStaleSessionsJob;
use App\Jobs\PruneStaleVisitorPresencesJob;
use App\Models\MarketingSetting;
use App\Models\Season;
use App\Support\ScheduleFailureSignal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Второй рубеж автоперевода обложек в WebP (H3082). Наблюдатель
        // ловит загрузку через Eloquent; эта уборка подбирает всё, что
        // прошло мимо модели, чтобы формат не зависел от человека.
        $schedule->command('media:covers-to-webp')
            ->dailyAt('02:40')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('media-covers-to-webp');

        $schedule->command('archives:cleanup --hours=24')
            ->dailyAt('03:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('archives-cleanup');

        // H3314: prune истёкших Sanctum-токенов мобильного API (expires_at в
        // прошлом) плюс legacy-строк старше окна sanctum.expiration - таблица
        // personal_access_tokens не растёт бесконечно, закат токенов задокумент-
        // ирован в DEPLOY_QUEUE.
        $schedule->command('tokens:prune-expired')
            ->dailyAt('03:20')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('sanctum-token-prune');

        // H3445: еженедельное обновление GeoLite2-City для SUPPORT_GEO_DRIVER=maxmind.
        // Команда сама выходит, если учётные данные MaxMind не заданы.
        $schedule->command('support:geo-update-maxmind')
            ->weeklyOn(0, '4:40')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->name('support-geo-maxmind-update');

        // Перевод просроченных promises в статус expired — ночью.
        // onFailure → ScheduleFailureSignal (H2338 / audit spec 7): log+admin
        // bell; without it, money crons fail only into laravel.log.
        $schedule->command('promises:expire')
            ->dailyAt('03:30')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->onFailure(fn () => ScheduleFailureSignal::report('promises:expire'))
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
            ->onFailure(fn () => ScheduleFailureSignal::report('receivables:check'))
            ->name('receivables-threshold-check');

        // Аудит денежных инвариантов (H2338 / audit spec 6): dry-run, FAILURE
        // при любом непустом bucket (в т.ч. paid-but-no-group). Alert path:
        // schedule exit ≠ 0 → onFailure → ScheduleFailureSignal (Log::critical
        // + Filament DB notification to super_admin/admin/accountant). Operators
        // also see ERROR lines from schedule:run in laravel.log. Manual:
        // `php artisan payments:audit-checkout-integrity`.
        $schedule->command('payments:audit-checkout-integrity')
            ->dailyAt('04:05')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->onFailure(fn () => ScheduleFailureSignal::report('payments:audit-checkout-integrity'))
            ->name('audit-checkout-integrity');

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

        // Мост «Расход»→Expense (H2003, issue #953): легаси-расходы, внесенные
        // платежами с тарифом «Расход», ежедневно доливаются в opex-леджер —
        // кокпит видит их, какой бы конвенцией расход ни внесли. Идемпотентен
        // по expenses.payment_id.
        $schedule->command('expenses:bridge-raskhod --apply')
            ->dailyAt('04:40')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expenses-bridge-raskhod');

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

        // NOBORING dozhim Wave 1b (H2059): задачи менеджеру + линейный дрип по
        // недожатым open Deal. Гейты (dozhim_queue / dozhim_drip) — внутри
        // команд; пока оба флага выключены, прогон — no-op. Задачи — перед
        // дрипом: тот же утренний слот, что и leads:remind-followup.
        $schedule->command('dozhim:create-followups')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('dozhim-create-followups');

        $schedule->command('dozhim:drip')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('dozhim-drip');

        // Решение MG 24-08-2026 (гибрид): будни 10:00 MSK — TG-сводка недожатых
        // владельцу очереди. Гейт dozhim_operator_notify — внутри команды;
        // пустая очередь молчит. Europe/Moscow = Минск круглый год, Рига
        // расходится только зимой (10:00 MSK = 09:00 EET).
        $schedule->command('dozhim:notify-operator')
            ->weekdays()
            ->dailyAt('10:00')
            ->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('dozhim-notify-operator');

        // H3209: вчера был слот в schedules, а записи в кабинете/ТГ нет.
        // Дедуп recording_gap:YYYY-MM-DD; n8n ZOOM 1.4 только читается, не ретраится.
        $schedule->command('recordings:gap-watch')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('recordings-gap-watch');

        // MG 24-08-2026: дневной урок не должен ждать утреннего прохода —
        // сегодня начатый слот старше RECORDING_GAP_STALE_HOURS без записи
        // тревожит в тот же день. Kill-switch RECORDING_GAP_STALE_ENABLED (default ON).
        $schedule->command('recordings:gap-watch', ['--stale' => true])
            ->hourlyAt(41)
            ->when(fn () => (bool) config('recording_gap.stale_enabled'))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('recordings-gap-watch-stale');

        // MG 24-08-2026: остаток OpenRouter + прогноз исчерпания по своим
        // снапшотам; за 14 дней до нуля — просьба пополнить на год вперёд.
        $schedule->command('openrouter:balance-check')
            ->dailyAt('09:20')
            ->when(fn () => (bool) config('openrouter.enabled'))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('openrouter-balance-check');

        // H3242: вчерашняя сводка поддержки на ADMIN_TELEGRAM_ID (gasyoun).
        // 08:10, после gap-watch; гейт флага — внутри команды (default ON).
        $schedule->command('support:daily-digest')
            ->dailyAt('08:10')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('support-daily-digest');

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
            ->onFailure(fn () => ScheduleFailureSignal::report('debts:remind'))
            ->name('remind-debtors');

        // Уведомление о недоборе группы за N дней до плановой даты старта
        // (recruitment_notify_lead_days, дефолт 2) — тот же утренний слот (H162).
        $schedule->command('groups:notify-forming-shortfall')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('notify-forming-shortfall');

        // Автовыдача сертификатов по вехам курсов (конец блока / N-е занятие /
        // урок учебника / занятия материала) + уведомление студентов. Гейт
        // (certificate_auto_issue_enabled), lookback и дедуп (unique
        // user+course+milestone+occurrence, notified_at) — внутри команды.
        $schedule->command('certificates:issue-milestones')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('issue-milestone-certificates');

        // Детектор курсов без вех сертификатов (>= N занятий, вех нет →
        // кураторский чат + пометка курса). Не зависит от гейта автовыдачи.
        $schedule->command('courses:detect-missing-milestones')
            ->dailyAt($paymentTime)
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('detect-missing-milestone-courses');

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

        // H3380 (24-08): вторая сессия rusamskrtam ВЫКЛЮЧЕНА. Открытие дня:
        // давний support-сеанс и так был аккаунтом @rusamskrtam (getSelf
        // id=5487293147), второй логин создавал дубль того же аккаунта —
        // нарушение D1 (два MTProto-логина = AUTH_RESTART пинг-понг).
        // Автоответ-проба продолжается на ОСНОВНОМ лейне: строке support
        // выставлены auto_reply_enabled=1 + hint_recipients; флаги те же.
        // Слот --account=rusamskrtam убран из расписания осознанно; строка
        // аккаунта оставлена is_enabled=0 с историей. Вернуть отдельную
        // сессию можно только для ДРУГОГО аккаунта.
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

        // H2635: two bounded evidence ingests, exactly 12 hours apart. The command and the support
        // reader share the same Madeline session lock; session_busy exits
        // non-zero so the scheduler records an honest missed run for retry.
        $schedule->command('telegram-harvest:sync --json')
            ->cron((string) config('services.telegram_harvest.daily_cron', '15 5,17 * * *'))
            ->withoutOverlapping($this->madelineSessionLockMinutes(600))
            ->onOneServer()
            ->when(fn (): bool => (bool) config('services.telegram_harvest.daily_enabled', false))
            ->name('telegram-harvest-twice-daily-sync');

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

        // --- ДЕТЕКТОР ОТСРОЧЕК ОПЛАТЫ В ПЕРЕПИСКЕ (H2156) ---
        // Twin of reminders:detect-requests; creates only PaymentPromiseSuggestion
        // (pending). Gate promise_suggestion_detection_enabled default OFF.
        $schedule->command('promises:detect-deferrals')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('detect-payment-promise-deferrals');

        $schedule->command('promises:expire-stale-suggestions')
            ->dailyAt('04:07')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('expire-stale-promise-suggestions');

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

        // H3371: докатка незавершённых групп split-upload (обрыв связи посреди
        // группы, лаг консистентности Яндекс WebDAV). Ежедневно до
        // backup:monitor — оборванная группа доплывает максимум за сутки.
        $schedule->command('backup:resume-yandex-parts')
            ->dailyAt('04:10')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->name('yandex-split-resume');

        // Daily destination health check (H2303): alerts via configured notification
        // channels if any destination is Unreachable or Unhealthy. Runs independently
        // of the weekly backup:run so a broken destination surfaces within 24 h.
        $schedule->command('backup:monitor')
            ->dailyAt('04:35')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('backup-destination-health');

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

        // --- РИДЕР БРОШЕННЫХ ЧЕКАУТОВ (H1358 + H2338) ---
        // Заваливает (failed) зависшие pending-платежи старше checkout.legacy_pending_days
        // (или webhook-буфера timed промо-брони), освобождая прану/реферальный
        // кредит/депозит/промо-слот через Payment::booted(). Deposit/trial/paypal/
        // conditional строки не трогает никогда. Частый слот (деньги, не «раз в сутки»
        // дебри) с построчным row-lock против гонки с банковским вебхуком. Без --apply
        // команда только отчитывается.
        //
        // Слот ВСЕГДА зарегистрирован (audit spec 7): иначе a false/stale config
        // cache silently drops the reaper from schedule:list with no log line.
        // features.checkout_stale_order_expiry is self-checked inside the command
        // (exit SUCCESS + warn when dark + --apply — no schedule ERROR spam).
        // Dry-run руками: `php artisan payments:expire-stale-checkouts`.
        $schedule->command('payments:expire-stale-checkouts --apply')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->onFailure(fn () => ScheduleFailureSignal::report('payments:expire-stale-checkouts'))
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
        //
        // НЕ здесь с 30-07-2026 (H1917): раньше `cabinet:probe` стоял *внутри*
        // schedule:run той же строкой `*/15`, что и здесь — а значит вставал
        // вместе с планировщиком (проверено H1917 живым зависанием: слот 11:30
        // выпал из schedule.log целиком). Сторож вынесен ОТДЕЛЬНОЙ строкой
        // cron — `systema-watchdog-run.sh "cabinet:probe" cabinet 120` в
        // scripts/server_guards/cron/app-user.crontab — со своим локом и
        // судьбой, не зависящей от schedule:run. Не возвращайте команду сюда.

        // --- ЧЛЕНСТВО (H2644, запуск клуба 01-09-2026) ---
        // Снятие клубного права по истечении оплаченного периода. Раньше выдачи
        // бесплатного уровня в то же утро намеренно: истёкший вчера клубный член
        // должен успеть стать кандидатом на бесплатный урок сегодня же, а не через
        // сутки. Обе команды при выключенных флагах (features.club_membership /
        // features.membership_free_tier) печатают отчёт и ничего не пишут —
        // планировщик безопасен до запуска.
        $schedule->command('membership:expire-club --apply')
            ->dailyAt('05:10')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->onFailure(fn () => ScheduleFailureSignal::report('membership:expire-club'))
            ->name('membership-expire-club');

        // Месячный урок бесплатного уровня. Ежедневно, а не «первого числа»:
        // право не копится (FreeTierLessonGranter), поэтому у каждого свой цикл
        // от даты его последнего гранта, и ежедневный проход подбирает тех, у
        // кого он истёк. «Первое число» собрало бы всех 350 в один пик и
        // привязало бы подарок к календарю школы, а не к ритму студента.
        $schedule->command('membership:grant-free-lesson --apply')
            ->dailyAt('05:25')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->onFailure(fn () => ScheduleFailureSignal::report('membership:grant-free-lesson'))
            ->name('membership-grant-free-lesson');
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
