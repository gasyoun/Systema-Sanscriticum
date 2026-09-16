<?php

namespace App\Console\Concerns;

use App\Support\ScheduleFailureSignal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

trait SchedulesOvernightAndCrm
{
    /** the 02:40-04:40 overnight block: media, archives, tokens, geo, money checks, storage, expenses bridge. */
    private function scheduleOvernightMaintenance(Schedule $schedule): void
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

    }

    /** finance/homework/goals digests (daily finance frame; homework/goals weekly). */
    private function scheduleWeeklyDigests(Schedule $schedule): void
    {
        // Ежедневный KPI-дайджест делегирования (H259 фаза D; с H4908 —
        // ежедневно по рулингу MG 15-09-2026): сводка всех фаз финдиру каждое
        // утро, включая пульс «активные платные ученики» строками — «ритм
        // обзора» с зубами. Гейт «есть получатели» — внутри команды.
        $schedule->command('finance:kpi-digest')
            ->dailyAt('08:40')
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

        // Каналы лидов → выручка (H5021): read-only report:channel-roi за 90 дней,
        // по источникам, сводка — в базу уведомлений получателям KPI-дайджеста.
        // Та же понедельничная рамка; данные ему даёт ночная leads:infer-source.
        $schedule->command('report:channel-roi --days=90 --by-source --digest')
            ->weeklyOn(1, '09:20')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('report-channel-roi-digest');
    }

    /** vacation quorum, waitlist, lead followups, subscription archive, dozhim. */
    private function scheduleCrmAndLifecycle(Schedule $schedule): void
    {
        // Каникулы групп (H3790, фаза C): 25–31.08 вопрос «когда возобновляем?»
        // в чаты групп; круглогодично — разрешение дедлайнов кворума. Окно
        // спрашивания проверяется внутри команды, расписание — ежедневное.
        $schedule->command('schedule:vacation-quorum')
            ->dailyAt('10:00')
            ->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('vacation-quorum-poll');

        // Список ожидания (MG 31-08-2026, волна 3): ежедневный движок порогов
        // голосов/оплат и лестницы переносов. Внутри — только статусы
        // course_waitlist_items; живые Schedule-строки создаёт куратор.
        $schedule->command('waitlist:process')
            ->dailyAt('10:20')
            ->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('waitlist-process');

        // Напоминание менеджеру о заявках с наступившим next_contact_at.
        // Гейт (crm_reminders) и дедуп (reminded_at) — внутри команды; пока
        // флаг выключен, прогон — no-op.
        $schedule->command('leads:remind-followup')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('remind-leads-followup');

        // Источник у каждого лида (H5021): ночью выводим inferred_source из
        // UTM/статьи/referrer/лид-магнита для строк без источника. Ручной
        // leads.source не трогает; идемпотентно (пишет только пустые).
        $schedule->command('leads:infer-source')
            ->dailyAt('03:35')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('leads-infer-source');

        // Подписка «в записи» (H3916): 6-месячное окно эксклюзивности.
        // Завершённый поток входит в архив подписки через 6 месяцев после
        // последнего занятия. Ежедневно ночью; без записи вне контура —
        // команда сама решает по датам расписания.
        $schedule->command('subscription:refresh-archive')
            ->dailyAt('03:40')
            ->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('subscription-archive-refresh');

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

    }
}
