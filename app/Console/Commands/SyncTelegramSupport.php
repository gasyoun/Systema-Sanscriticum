<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Exceptions\MadelineSyncTimedOut;
use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncWatchdog;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncTelegramSupport extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-support:sync {--payload= : JSON file with normalized Telegram messages for local import}';

    protected $description = 'Sync Telegram support-account messages and rebuild daily support analytics.';

    public function handle(
        TelegramSupportSyncService $sync,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        $payloadPath = $this->option('payload');

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

            $result = $sync->syncNormalizedMessages($payload);
        } else {
            $timeout = (int) config('services.telegram_support.sync_timeout_seconds', 120);

            // Потолок времени взводим ТОЛЬКО на live-пути: импорт из payload в сеть
            // не ходит и зависнуть не может. Без потолка заход живёт часами, замок
            // планировщика протухает и на той же сессии стартует второй экземпляр
            // (см. MadelineSyncWatchdog).
            if (! $watchdog->arm($timeout) && $timeout > 0 && $this->getOutput()->isVerbose()) {
                $this->warn('Watchdog недоступен (нет расширения pcntl) — заход идёт без потолка времени.');
            }

            try {
                // Live path opens the shared MadelineProto session — serialise it
                // against telegram-harvest:sync / :peers (see LocksMadelineSession).
                $result = $this->withMadelineSessionLock(fn () => $sync->sync());
            } catch (MadelineSyncTimedOut $e) {
                return $this->failOnTimeout($reaper, $e);
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
     * Заход прервали по таймауту. Замок сессии к этому моменту уже отпущен
     * (исключение раскрутило стек через finally трейта), осталось прибрать за
     * собой демона этой сессии — иначе он переживёт нас и продолжит держать
     * дескрипторы, — и оставить оператору внятный след в карточке аккаунта.
     */
    private function failOnTimeout(MadelineSessionReaper $reaper, MadelineSyncTimedOut $e): int
    {
        $killed = $reaper->killDaemons();
        $removed = $reaper->clearIpcArtifacts();

        Log::error('Telegram support sync timed out — process stopped by watchdog', [
            'timeout_seconds' => $e->timeoutSeconds,
            'killed_processes' => $killed,
            'removed_files' => $removed,
        ]);

        // Аккаунт мог ещё не существовать (первый же заход завис) — тогда просто
        // нечего помечать, вся диагностика уже в логе.
        TelegramSupportAccount::query()
            ->where('name', 'support')
            ->update([
                'last_synced_at' => now(),
                'last_sync_error' => "Заход прерван по таймауту ({$e->timeoutSeconds} с); демон сессии сброшен.",
            ]);

        $this->error("Telegram support sync: timeout после {$e->timeoutSeconds} с — процесс остановлен, демон сессии сброшен.");

        return self::FAILURE;
    }
}
