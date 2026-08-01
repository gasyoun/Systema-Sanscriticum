<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Авто-восстановление застрявшей MTProto-сессии support (IPC hang).
 *
 * Ручной ранбук 01.08.2026: kill worker → rm ipc/locks → forceRelease
 * madeline-session → telegram-support:sync. Здесь — программный аналог
 * поверх {@see MadelineSessionReaper}, без удаления safe.php (логин).
 */
class MadelineSessionHealer
{
    public const COOLDOWN_CACHE_KEY = 'telegram-support:auto-heal:last_at';

    public const LOCK_NAME = 'madeline-session';

    /** Подстроки last_sync_error, при которых имеет смысл heal (не flood, не AUTH). */
    private const HEALABLE_ERROR_NEEDLES = [
        'Could not connect to MadelineProto',
        'proc_open',
        'IPC',
        'ipc',
        'ChannelException',
        'Did the context die',
        'session is busy',
        'Resource temporarily unavailable',
        'Still waiting for exclusive session lock',
    ];

    public function __construct(
        private MadelineSessionReaper $reaper,
    ) {}

    public function autoHealEnabled(): bool
    {
        return (bool) config('services.telegram_support.auto_heal', false);
    }

    public function cooldownMinutes(): int
    {
        return max(5, (int) config('services.telegram_support.auto_heal_cooldown_minutes', 30));
    }

    public function isInCooldown(): bool
    {
        $last = Cache::get(self::COOLDOWN_CACHE_KEY);
        if ($last === null) {
            return false;
        }

        return now()->diffInMinutes($last) < $this->cooldownMinutes();
    }

    public function markCooldown(): void
    {
        Cache::put(self::COOLDOWN_CACHE_KEY, now(), now()->addDay());
    }

    public function errorLooksHealable(?string $error): bool
    {
        if ($error === null || trim($error) === '') {
            return false;
        }

        foreach (self::HEALABLE_ERROR_NEEDLES as $needle) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   killed: int,
     *   killed_hard: int,
     *   removed: list<string>,
     *   lock_released: bool,
     *   sync_status: ?string,
     *   sync_error: ?string,
     * }
     */
    public function recover(bool $runSync = true): array
    {
        $killed = $this->reaper->killDaemons();
        // D-state / zombie IPC: SIGTERM часто не достаточен (01.08.2026).
        usleep(300_000);
        $killedHard = $this->reaper->killDaemonsHard();

        $removed = $this->reaper->clearIpcArtifacts();
        $removed = array_values(array_unique(array_merge($removed, $this->reaper->clearLockArtifacts())));

        $lockReleased = false;
        try {
            Cache::lock(self::LOCK_NAME, 900)->forceRelease();
            $lockReleased = true;
        } catch (\Throwable $e) {
            Log::warning('MadelineSessionHealer: forceRelease failed', ['error' => $e->getMessage()]);
        }

        $syncStatus = null;
        $syncError = null;

        if ($runSync) {
            // Дать ОС отпустить сокеты/fd после kill.
            sleep(2);
            try {
                Artisan::call('telegram-support:sync');
                $output = Artisan::output();
                $syncStatus = trim($output) !== '' ? trim($output) : 'called';
            } catch (\Throwable $e) {
                $syncError = $e->getMessage();
                Log::error('MadelineSessionHealer: post-recover sync failed', ['error' => $syncError]);
            }
        }

        $result = [
            'killed' => $killed,
            'killed_hard' => $killedHard,
            'removed' => $removed,
            'lock_released' => $lockReleased,
            'sync_status' => $syncStatus,
            'sync_error' => $syncError,
        ];

        Log::warning('MadelineSessionHealer: recover completed', $result);

        return $result;
    }
}
