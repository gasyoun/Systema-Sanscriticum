<?php

namespace App\Console\Concerns;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

trait SchedulesTelegram
{
    /** telegram-support sync/catch-up/healthcheck (one MTProto session). */
    private function scheduleTelegramSupport(Schedule $schedule): void
    {
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

        // H4416 (08-09-2026): суточный catch-up — полный обмет всех известных
        // чатов с активностью за TELEGRAM_SUPPORT_CATCHUP_DAYS (по умолчанию 60).
        // Страховка минутному горячему окну (known_chat_window_days): чат,
        // оживший после долгой паузы, мог выпасть и из окна, и из MP-топа —
        // ровно так умерли DM 31-08…08-09 (аутедж, кейс Елены Безрядиной).
        // Cursor по peer делает повторный обмет дешёвым; withoutOverlapping
        // тем же TTL замка — сессия одна. Слот 05:37 — после суточного харвеста
        // (05:15/17:15 по daily_cron), чтобы не спорить за замок сессии.
        $schedule->command('telegram-support:sync --catch-up-days='.(int) config('services.telegram_support.catchup_days', 60))
            ->dailyAt('05:37')
            ->withoutOverlapping($syncLockMinutes)
            ->onOneServer()
            ->name('telegram-support-sync-catchup');

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

    }

    /** roster harvest + twice-daily evidence sync (same session lock). */
    private function scheduleTelegramHarvest(Schedule $schedule): void
    {
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
        //
        // H3411: this Laravel version's Event builder has no ->timeout(...) —
        // Event::run() calls Process::fromShellCommandline() with a hardcoded
        // null timeout, so there is no framework-level ceiling to add here.
        // The real ceiling is two-layered: (1) MadelineSyncWatchdog::arm() inside
        // SyncTelegramHarvest::handle() (SIGALRM after sync_timeout_seconds,
        // config('services.telegram_harvest.sync_timeout_seconds')), and
        // (2) systema-schedule-run.sh's `timeout` wrapping schedule:run itself
        // (see that script's header for why a detached MadelineProto daemon can
        // still outlive both — H1973/H3121). Sibling audit for this same gap
        // (H3411 Deliverable 1) covered every telegram-* scheduled entry above:
        // telegram-support:sync, telegram-harvest:roster-groups and this command
        // all arm a watchdog before touching the shared session. One gap found
        // outside this list: telegram-support:healthcheck (everyFifteenMinutes,
        // below) can call telegram-support:recover → MadelineSessionHealer::recover(),
        // whose own kill/clear steps (killDaemons/killDaemonsHard/clearIpcArtifacts)
        // run with no watchdog at all — only the nested Artisan::call('telegram-support:sync')
        // inside it is protected (SyncTelegramSupport arms its own watchdog, which
        // fires regardless of call depth). Left as a follow-up: it's a different
        // command family (support-session recovery, not harvest sync) and fixing
        // it here would expand this handoff's blast radius beyond its named target.
        // H3411 Deliverable 3: every other scheduled command family above has an
        // ->onFailure() pager (ScheduleFailureSignal, money-scoped); this one had
        // none — a stuck run (MadelineSyncWatchdog::arm() exits non-zero on SIGALRM,
        // see EXIT_TIMED_OUT) or any other non-zero exit vanished into laravel.log
        // with nobody paged. Not routed through ScheduleFailureSignal itself: that
        // reporter pages finance/accountant roles with "Сбой денежного cron" copy,
        // which would misattribute a harvester stall as a money-command failure.
        $schedule->command('telegram-harvest:sync --json')
            ->cron((string) config('services.telegram_harvest.daily_cron', '15 5,17 * * *'))
            ->withoutOverlapping($this->madelineSessionLockMinutes(600))
            ->onOneServer()
            ->when(fn (): bool => (bool) config('services.telegram_harvest.daily_enabled', false))
            ->onFailure(fn () => Log::critical('schedule.telegram_harvest_sync_failed', [
                'command' => 'telegram-harvest:sync --json',
                'hint' => 'Non-zero exit (stuck/timed-out run or genuine failure) — see docs/SERVER_SOFT_ALERT_PLAYBOOK.md and laravel.log around this timestamp.',
            ]))
            ->name('telegram-harvest-twice-daily-sync');

    }

    /** zapisi bot notices + scheduled/adaptive reminders + promise suggestions. */
    private function scheduleZapisiAndReminders(Schedule $schedule): void
    {
        // Track C (H164): @zapisi_ORSbot напоминает о занятии в чат группы прямо
        // из расписания (Schedule → group.telegram_chat_id). No-op, пока не включён
        // features.telegram_zapisi_bot; окно и дедуп (zapisi_reminded_at) — внутри команды.
        $schedule->command('zapisi:remind-classes')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('zapisi-remind-classes');

        // MG 08-09: слотовые уведомления — «сегодня занятия нет» в обычный слот
        // (перенос/отмена) и напоминание об оплате после каждого 4-го занятия
        // блока. No-op без features.telegram_zapisi_bot; дедуп клеймами внутри.
        $schedule->command('zapisi:slot-notices')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('zapisi-slot-notices');

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

    }
}
