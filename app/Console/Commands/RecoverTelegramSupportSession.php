<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\MadelineSessionHealer;
use Illuminate\Console\Command;

/**
 * Ручной / авто-ранбук: сброс застрявшего IPC worker'а support-сессии.
 *
 * Не удаляет safe.php — логин сохраняется. См. MadelineSessionHealer.
 */
class RecoverTelegramSupportSession extends Command
{
    protected $signature = 'telegram-support:recover
        {--force : Игнорировать cooldown и эвристику «healable error»}
        {--no-sync : Только kill + clear IPC + unlock, без telegram-support:sync}
        {--dry : Показать, что сделали бы, без действий}';

    protected $description = 'Сбросить застрявший MadelineProto IPC support-сессии (kill worker, clear sockets, unlock, resync).';

    public function handle(MadelineSessionHealer $healer): int
    {
        if ($this->option('dry')) {
            $this->comment('--dry: kill daemons → clear ipc/locks → forceRelease(madeline-session) → optional sync');
            $this->line('auto_heal='.($healer->autoHealEnabled() ? 'on' : 'off')
                .' cooldown_min='.$healer->cooldownMinutes()
                .' in_cooldown='.($healer->isInCooldown() ? 'yes' : 'no'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && $healer->isInCooldown()) {
            $this->warn('В cooldown auto-heal ('.$healer->cooldownMinutes().' мин) — пропуск. --force чтобы обойти.');

            return self::SUCCESS;
        }

        $this->info('Recovering MadelineProto support session…');

        $result = $healer->recover(runSync: ! $this->option('no-sync'));
        $healer->markCooldown();

        $this->line('killed_term='.$result['killed'].' killed_kill='.$result['killed_hard']);
        $this->line('removed='.($result['removed'] === [] ? '—' : implode(',', $result['removed'])));
        $this->line('lock_released='.($result['lock_released'] ? 'yes' : 'no'));
        if ($result['sync_status'] !== null) {
            $this->info($result['sync_status']);
        }
        if ($result['sync_error'] !== null) {
            $this->error('sync error: '.$result['sync_error']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
