<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Exceptions\MadelineSyncTimedOut;
use App\Models\Group;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncWatchdog;
use App\Services\TelegramHarvest\RosterStoreWriter;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * D9 (Track C): снимает ростер (состав участников) СРАЗУ для всех учебных групп
 * с заполненным telegram_chat_id — за один проход под общим замком сессии
 * (mirror telegram-harvest:roster, но батчем по Group.telegram_chat_id).
 *
 * Пишет roster/{telegram_chat_id}.json — тот же ключ, что читает дашборд
 * «Записи (бот)» → «Состав чата». Наполняет его сам, без ручных команд по чату.
 *
 * No-op, если харвест/support выключены или MadelineProto не сконфигурирован.
 * Снимаются только чаты, где аккаунт-харвестер СОСТОИТ (getDialogIds-прайминг
 * видит лишь их); прочие тихо пропускаются и попадают в «не снято».
 */
class SnapshotGroupRosters extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-harvest:roster-groups';

    protected $description = 'Снимает ростер всех учебных групп с telegram_chat_id через юзербот (MadelineProto), один проход под замком сессии.';

    public function handle(
        TelegramHarvestSyncService $harvest,
        MadelineClientFactory $clientFactory,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        if (! config('services.telegram_harvest.enabled')) {
            $this->info('Telegram harvest выключен (TELEGRAM_HARVEST_ENABLED) — пропуск.');

            return self::SUCCESS;
        }

        if (! config('services.telegram_support.enabled')) {
            $this->info('Support-сессия выключена — у харвестера нет своей сессии, пропуск.');

            return self::SUCCESS;
        }

        if (! $clientFactory->isConfigured()) {
            $this->info('MadelineProto не сконфигурирован (api_id/api_hash) — пропуск.');

            return self::SUCCESS;
        }

        $peers = Group::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->whereIn('status', ['active', 'forming'])
            ->pluck('telegram_chat_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($peers === []) {
            $this->info('Групп с telegram_chat_id нет — нечего снимать.');

            return self::SUCCESS;
        }

        $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        $writer = new RosterStoreWriter($path);

        // Пишем КАЖДЫЙ ростер сразу, а не пачкой в конце: проход по всем группам
        // идёт минутами, и прерванный (таймаут/флуд) не должен обнулять уже
        // снятое.
        $written = 0;
        $writeRoster = function (string $peer, array $roster) use ($writer, &$written): void {
            $writer->write($peer, $roster);
            $written++;
        };

        // Потолок времени всего прохода: у getPwrChat своего таймаута нет, при
        // флуд-лимите MadelineProto уходит в сон на часы и держит общую сессию —
        // тот же класс отказа, что 27.07.2026 положил telegram-support:sync.
        $timeout = (int) config('services.telegram_harvest.roster_timeout_seconds', 600);
        if (! $watchdog->arm($timeout) && $timeout > 0 && $this->getOutput()->isVerbose()) {
            $this->warn('Watchdog недоступен (нет расширения pcntl) — проход идёт без потолка времени.');
        }

        try {
            // Весь проход — под ОДНИМ замком: сессия открывается один раз (иначе
            // второй демон крадёт IPC-сокеты). null = сессию держит другая команда.
            $rosters = $this->withMadelineSessionLock(
                fn (): array => $harvest->fetchGroupRosters($peers, $writeRoster),
            );
        } catch (MadelineSyncTimedOut $e) {
            // Замок уже отпущен раскруткой стека; прибираем демона своей сессии,
            // чтобы он не пережил нас и не держал дескрипторы.
            $reaper->killDaemons();
            $reaper->clearIpcArtifacts();

            Log::error('Telegram harvest roster-groups timed out — process stopped by watchdog', [
                'timeout_seconds' => $e->timeoutSeconds,
                'rosters_written' => $written,
                'peers' => count($peers),
            ]);

            $this->error("Ростеры: timeout после {$e->timeoutSeconds} с — снято {$written} из ".count($peers).', демон сессии сброшен.');

            return self::FAILURE;
        } finally {
            $watchdog->disarm();
        }

        if ($rosters === null) {
            $this->warn('Telegram harvest roster-groups: session_busy (сессию держит другая команда). Повтор позже.');

            return self::SUCCESS;
        }

        $skipped = count($peers) - $written;
        $this->info("Ростеров снято: {$written} из ".count($peers).($skipped > 0 ? " (не снято: {$skipped} — возможно, аккаунт не в чате)." : '.'));

        return self::SUCCESS;
    }
}
