<?php

namespace App\Console\Concerns;

use App\Support\ScheduleFailureSignal;
use Illuminate\Console\Scheduling\Schedule;

trait SchedulesOpsAndMembership
{
    /** weekly backup run/clean + daily destination health check. */
    private function scheduleBackups(Schedule $schedule): void
    {
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

        // H3371 → H3410: докатка незавершённых групп split-upload (обрыв связи
        // посреди группы, лаг консистентности Яндекс WebDAV) БОЛЬШЕ НЕ живёт
        // здесь. 24-08-2026 SOS-разбор нашёл PUT, застрявший в TLS sendto()
        // EAGAIN без прогресса 30+ минут — под cron.service это повторило бы
        // класс аварий §2/§9 docs/server-resource-guards.md (зависшая команда
        // держит schedule:run в foreground, планировщик копится под чужим
        // MemoryHigh). Теперь её поднимает systema-yandex-resume.service/.timer
        // — свой бюджет, свой таймаут, часовой такт вместо суточного (докатка
        // дешева, когда докатывать нечего). Разбор: docs/server-resource-guards.md §12.

        // Daily destination health check (H2303): alerts via configured notification
        // channels if any destination is Unreachable or Unhealthy. Runs independently
        // of the weekly backup:run so a broken destination surfaces within 24 h.
        $schedule->command('backup:monitor')
            ->dailyAt('04:35')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('backup-destination-health');

    }

    /** FAQ answer suggester/rollups + stale checkout reaper. */
    private function scheduleFaqAndCheckout(Schedule $schedule): void
    {
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

    }

    /** heartbeat ping + CSRF mismatch digest (+ cabinet:probe note). */
    private function scheduleWatchdogs(Schedule $schedule): void
    {
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

        // --- ЛОГ-СТОРОЖ 500-КЛАССА (H4648) ---
        // `logs:error-watch` — там же, рядом с cabinet:probe, и по той же
        // причине НЕ здесь: третья строка `systema-watchdog-run.sh
        // "logs:error-watch" logs-watch 120` в app-user.crontab (своя судьба,
        // свой лок — урок H1917). Числа: scripts/server_guards.conf
        // (WATCHDOG_LOGS_WATCH_*); сверка живой машины: guards:verify.
        // Всплеск production.ERROR (≥3/ч, config/logs_watch.php) → TG soft.

        // --- MONEY-AXIS SLI (H4672) ---
        // `money:sli-synthetic-pay` (ежесуточно) и `money:sli-hourly-reconcile`
        // (ежечасно) НЕ здесь, по той же причине H1917 что и выше: отдельные
        // строки `systema-watchdog-run.sh "money:sli-synthetic-pay" money-sli-daily …`
        // / `"money:sli-hourly-reconcile" money-sli-hourly …` в
        // app-user.crontab. Числа: scripts/server_guards.conf
        // (WATCHDOG_MONEY_SLI_DAILY_*/WATCHDOG_MONEY_SLI_HOURLY_*). Оба флага
        // (features.money_sli_synthetic_pay/_hourly_reconcile) default OFF —
        // включение в проде отдельный ops-шаг, см. DEPLOY_QUEUE.md.
    }

    /** club membership expiry/free lesson + PayPal fixed prices. */
    private function scheduleMembershipAndPaypal(Schedule $schedule): void
    {
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

        // --- PAYPAL: FIXED EUR/USD PRICE LIST (H3821) ---
        // Ежемесячный пересчёт published fixed price за тариф — заменяет ад-хок
        // ручную конвертацию, которую нашла сверка H3819 (0-18% разброс на
        // идентичном тарифе). Гейт flag'ом: пока features.paypal_fixed_price_list
        // выключен (дефолт), слот — no-op, ничего не пишет.
        $schedule->command('paypal:refresh-foreign-prices')
            ->monthlyOn(1, '05:40')
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.paypal_fixed_price_list'))
            ->withoutOverlapping(30)
            ->onOneServer()
            ->name('paypal-refresh-foreign-prices');
    }
}
