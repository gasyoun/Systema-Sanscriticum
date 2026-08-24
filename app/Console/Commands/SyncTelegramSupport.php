<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncPhase;
use App\Services\Telegram\MadelineSyncWatchdog;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncTelegramSupport extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-support:sync
        {--payload= : JSON file with normalized Telegram messages for local import}
        {--account=support : Named telegram_support_accounts row whose session this run opens}';

    protected $description = 'Sync Telegram support-account messages and rebuild daily support analytics.';

    public function handle(
        TelegramSupportSyncService $sync,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        $payloadPath = $this->option('payload');
        $accountName = (string) ($this->option('account') ?: 'support');

        if ($payloadPath) {
            if (! File::exists($payloadPath)) {
                $this->error("Payload file not found: {$payloadPath}");

                return self::FAILURE;
            }

            $payload = json_decode(File::get($payloadPath), true);
            if (! is_array($payload)) {
                $this->error('Payload must be a JSON array.');

                return self::FAILURE;
            }

            $result = $sync->syncNormalizedMessages($payload, $accountName);
        } else {
            // H3380: не-дефолтный аккаунт = другая сессия. Переключаем контекст
            // ДО замка/watchdog'а/клиента: замок, фазы, reaper и фабрика читают
            // путь в момент вызова и разойдутся по сессиям сами.
            if ($accountName !== 'support') {
                $prepared = $this->prepareNamedAccountSession($accountName);
                if ($prepared !== true) {
                    $this->error((string) $prepared);

                    return self::FAILURE;
                }
            }

            $timeout = (int) config('services.telegram_support.sync_timeout_seconds', 120);
            $cooldown = (int) config('services.telegram_support.sync_timeout_cooldown_seconds', 600);

            // После watchdog-kill демон сессии сброшен. Следующая минута
            // cold-start'ит тот же DC; если столл ещё жив — снова 120 с и
            // снова kill. Прод 16–17-08-2026: 18 таких кругов за ~100 мин при
            // здоровом заходе 11–41 с. Кулдаун режет частоту, не потолок.
            if ($cooldown > 0 && MadelineSyncPhase::cooldownActive()) {
                Log::warning('Telegram support sync skipped: post-timeout cooldown', [
                    'cooldown_seconds' => $cooldown,
                    'last_phase' => MadelineSyncPhase::current(),
                ]);
                $this->warn('Telegram support sync: post_timeout_cooldown (waiting after watchdog kill).');

                return self::SUCCESS;
            }

            // Потолок времени взводим ТОЛЬКО на live-пути: импорт из payload в сеть
            // не ходит и зависнуть не может. Без потолка заход живёт часами, замок
            // планировщика протухает и на той же сессии стартует второй экземпляр
            // (см. MadelineSyncWatchdog).
            //
            // Уборка передаётся В watchdog, а не пишется в catch: обработчик
            // таймаута завершает процесс через exit(), и никакой catch/finally
            // здесь уже не отработает (H1915 — почему не через исключение).
            $armed = $watchdog->arm($timeout, fn (int $seconds) => $this->cleanUpAfterTimeout($reaper, $seconds, $accountName));

            if (! $armed && $timeout > 0) {
                // ГРОМКО, а не только в verbose: без watchdog'а единственной оградой
                // остаётся внешний потолок обёртки (SYSTEMA_SCHEDULE_MAX_SECONDS),
                // и TTL замка планировщика посчитан именно из него — молчать об этом
                // значит обещать инвариант, которого нет.
                Log::error('Telegram support sync идёт БЕЗ потолка времени: watchdog не взвёлся (нет расширения pcntl).', [
                    'timeout_seconds' => $timeout,
                    'account' => $accountName,
                ]);
                $this->warn('Watchdog недоступен (нет расширения pcntl) — заход идёт без потолка времени.');
            }

            try {
                // Live path opens the shared MadelineProto session — serialise it
                // against telegram-harvest:sync / :peers (see LocksMadelineSession).
                $result = $this->withMadelineSessionLock(fn () => $sync->sync($accountName));
            } finally {
                $watchdog->disarm();
            }

            if ($result === null) {
                Log::warning('Telegram support sync skipped: MadelineProto session busy.');
                $this->warn('Telegram support sync: session_busy (another MadelineProto command holds the session).');

                return self::SUCCESS;
            }
        }

        $line = 'Telegram support sync: '.$result['status'].'; synced='.$result['synced'];
        if (! empty($result['dates'])) {
            $line .= '; dates='.implode(',', $result['dates']);
        }
        if (array_key_exists('auto_linked', $result)) {
            $line .= '; auto_linked='.$result['auto_linked'];
        }
        if (! empty($result['error'])) {
            $line .= '; error='.$result['error'];
        }

        $this->info($line);

        return self::SUCCESS;
    }

    /**
     * Заход упёрся в потолок времени. Выполняется ВНУТРИ обработчика SIGALRM,
     * непосредственно перед exit() — то есть это единственный шанс прибрать за
     * собой: ни `finally` вызывающего, ни деструкторы на это уже не рассчитывают.
     *
     * Порядок намеренный. Сначала замок сессии: он взят на 900 с, и оставленный
     * висеть заблокировал бы следующие ~15 минут заходов после КАЖДОГО таймаута.
     * Затем демон этой сессии — иначе он переживёт нас и продолжит держать
     * дескрипторы (тот самый EMFILE 27.07.2026). И только потом след оператору.
     */
    private function cleanUpAfterTimeout(MadelineSessionReaper $reaper, int $seconds, string $accountName = 'support'): void
    {
        $this->releaseMadelineSessionLock();

        $killed = $reaper->killDaemons();
        $removed = $reaper->clearIpcArtifacts();

        $cooldown = (int) config('services.telegram_support.sync_timeout_cooldown_seconds', 600);
        MadelineSyncPhase::armCooldown($cooldown);

        Log::error('Telegram support sync timed out — process stopped by watchdog', [
            'timeout_seconds' => $seconds,
            'account' => $accountName,
            'killed_processes' => $killed,
            'removed_files' => $removed,
            'phase' => MadelineSyncPhase::current(),
            'cooldown_seconds' => $cooldown,
        ]);

        // Аккаунт мог ещё не существовать (первый же заход завис) — тогда просто
        // нечего помечать, вся диагностика уже в логе.
        TelegramSupportAccount::query()
            ->where('name', $accountName)
            ->update([
                'last_synced_at' => now(),
                'last_sync_error' => "Заход прерван по таймауту ({$seconds} с); демон сессии сброшен.",
            ]);

        $this->error("Telegram support sync: timeout после {$seconds} с — процесс остановлен, демон сессии сброшен.");
    }

    /**
     * Переключить этот процесс на сессию именованного аккаунта. Возвращает true
     * либо текст ошибки для --error.
     */
    private function prepareNamedAccountSession(string $accountName): bool|string
    {
        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->where('name', $accountName)->first();

        if ($account === null) {
            return "Аккаунт «{$accountName}» не заведён в telegram_support_accounts.";
        }

        // H3380 (урок 24-08): named-аккаунт может совпасть личностью с основным
        // support-аккаунтом — тогда вторая сессия того же аккаунта нарушает D1
        // (два MTProto-логина = AUTH_RESTART пинг-понг). Отключённая строка
        // (is_enabled=0) не открывается вовсе.
        if (! $account->is_enabled) {
            return "Аккаунт «{$accountName}» отключён (is_enabled=0) — синк не запускается.";
        }

        if ((string) $account->session_path === '') {
            return "У аккаунта «{$accountName}» не задан session_path — интерактивный логин ещё не выполнялся.";
        }

        $sessionPath = preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', (string) $account->session_path) === 1
            ? (string) $account->session_path
            : base_path((string) $account->session_path);

        MadelineSessionContext::useSession($sessionPath);

        return true;
    }
}
