<?php

namespace App\Console\Concerns;

use App\Models\MarketingSetting;
use App\Support\ScheduleFailureSignal;
use Illuminate\Console\Scheduling\Schedule;

trait SchedulesSupportAndPayments
{
    /** recording gap watchdogs (nightly sweep + stale hourly). */
    private function scheduleRecordingGaps(Schedule $schedule): void
    {
        // H3209: вчера был слот в schedules, а записи в кабинете/ТГ нет.
        // Дедуп персистентный — таблица recording_gap_alerts (H3557); n8n ZOOM 1.4 только читается, не ретраится.
        $schedule->command('recordings:gap-watch')
            ->dailyAt('08:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('recordings-gap-watch');

        // MG 24-08-2026: дневной урок не должен ждать утреннего прохода —
        // сегодня начатый слот старше RECORDING_GAP_STALE_HOURS без записи
        // тревожит в тот же день. Kill-switch RECORDING_GAP_STALE_ENABLED (default ON).
        // H3652: --stale — флаг без значения; форма ['--stale' => true]
        // компилируется в --stale=1 и symfony/console валит тик
        // («The --stale option does not accept a value»).
        $schedule->command('recordings:gap-watch --stale')
            ->hourlyAt(41)
            ->when(fn () => (bool) config('recording_gap.stale_enabled'))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('recordings-gap-watch-stale');

    }

    /** OpenRouter balance, support digests/SLA, FAQ knowledge indexing. */
    private function scheduleSupportAndKnowledge(Schedule $schedule): void
    {
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

        // H4429 (рулинг MG 08-09-2026: «live после недели тени, без
        // переспрашивания»): авто-рубильник живого режима LLM-ветки — 7
        // продуктивных дней тени подряд, включение + аудит-событие. Гейты
        // внутри команды; ручной просмотр — support:llm-live-enable --dry.
        $schedule->command('support:llm-live-enable')
            ->dailyAt('09:00')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('support-llm-live-enable');

        // H3392: недельный разбор пробы автоответов H3380 — «разбираем что
        // пошло не так» само-сборкой. Воскресенье 18:00 MSK; гейт флага
        // SUPPORT_AUTO_REPLY_WEEKLY_REPORT (default OFF): пока OFF, слот молчит;
        // ручной просмотр — php artisan support:auto-reply-weekly --dry.
        $schedule->command('support:auto-reply-weekly')
            ->sundays()
            ->at('18:00')
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.support_auto_reply_weekly_report'))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('support-auto-reply-weekly');

        // H3999 (шаг I3): недельный список незалинкованных контактов с 2+
        // сообщениями — для РУЧНОЙ привязки. Ничего студентам не шлёт; гейт —
        // тот же флаг приглашения, потому что без него список некуда девать.
        // Ручной просмотр: php artisan support:link-invite-census --dry.
        $schedule->command('support:link-invite-census')
            ->sundays()
            ->at('18:20')
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.support_dm_link_invite'))
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('support-link-invite-census');

        // H3999 (рулинг A5): SLA-сеть по открытым тредам без ответа. Каждые
        // пять минут — порог считается в РАБОЧИХ минутах, и более редкий слот
        // размазал бы обещанные 15 минут до получаса. Тихие часы и пустой
        // список кураторов команда отбивает сама; флаг default OFF.
        $schedule->command('support:sla-escalate')
            ->everyFiveMinutes()
            ->when(fn () => (bool) config('features.support_sla_escalation'))
            ->withoutOverlapping(5)
            ->onOneServer()
            ->name('support-sla-escalate');

        // H4608: недельный дайджест MIC shadow-телеметрии — uncategorized
        // top-50 + near-miss пары (G2 «телеметрия нулей»: пустой отчёт сам
        // по себе сигнал «классификатор молчит»). Пишет только файл в
        // storage, никому ничего не шлёт; гейт флага НЕ нужен — команда
        // read-only и без телеметрии честно печатает «no rows».
        $schedule->command('support:mic-null-digest')
            ->weeklyOn(1, '6:55')
            ->timezone('Europe/Moscow')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('mic-null-digest');

        // H4001 (Wave 3 leverage-плана): индексация FAQ-корпуса в
        // knowledge_chunks. Двойной гейт — флаг гибрида (OFF по умолчанию) И
        // настроенный драйвер эмбеддингов: пока dense-нога не включена
        // человеком, слот молчит. Ретраи живут внутри KnowledgeEmbedChunksJob
        // (очередь imports). 10:00 МСК, а не ночь: GPU-узел Ивана живёт
        // только 9–21 МСК — ночной слот гарантированно упирался бы в
        // спящий туннель и плодил failed jobs.
        $schedule->command('knowledge:index')
            ->dailyAt('10:00')
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.faq_hybrid_retrieval')
                && (string) config('knowledge.driver') !== '')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('knowledge-index');

        // Этап 4: свежие расшифровки уроков в ту же таблицу, полоса `lesson`.
        // Двойной гейт тот же: флаг вопросов по урокам (OFF по умолчанию) И
        // настроенный драйвер. Слот на полчаса позже FAQ-индексации — обе
        // ходят в один туннель, и незачем будить узел двумя пачками разом.
        $schedule->command('knowledge:index-lessons')
            ->dailyAt('10:30')
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.lesson_qa')
                && (string) config('knowledge.driver') !== '')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->name('knowledge-index-lessons');

        // H5065: досыл ответов полосы Telegram Business. Основной путь — сразу
        // после приёма апдейта (джоба дёргает дренаж, Bot API не боится
        // воркера), слот здесь — страховка на потерянный/упавший джоб. Гейт
        // флага: пока полоса выключена, команда выходит сразу, ничего не читая.
        $schedule->command('support:business-drain')
            ->everyMinute()
            ->timezone('Europe/Moscow')
            ->when(fn () => (bool) config('features.telegram_business_bot'))
            ->withoutOverlapping(5)
            ->onOneServer()
            ->name('support-business-drain');

    }

    /** MarketingSetting-timed payment/debt/certificate reminders. */
    private function schedulePaymentReminders(Schedule $schedule): void
    {
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

    }
}
