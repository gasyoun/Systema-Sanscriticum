<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncWatchdog;
use App\Services\Telegram\TelegramFolderAudience;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Сухой прогон рассылки по папке Telegram: кто в папке и кому можно писать.
 * Команда ничего не отправляет. Имена и логины — только в CSV (chmod 600),
 * в вывод идут счётчики: вывод попадает в логи и транскрипты агентов.
 */
class TelegramFolderAudienceCommand extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-folder:audience
        {folder : Название папки Telegram}
        {--account=support : Строка telegram_support_accounts, чья сессия открывается}';

    protected $description = 'Read-only: состав папки Telegram как список получателей (CSV + счётчики). Ничего не отправляет.';

    public function handle(
        TelegramFolderAudience $audience,
        MadelineClientFactory $clientFactory,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        if (! $clientFactory->isConfigured()) {
            $this->error('MadelineProto не сконфигурирован (api_id/api_hash).');

            return self::FAILURE;
        }

        $accountName = (string) $this->option('account');
        if ($accountName !== 'support' && ($error = $this->useNamedSession($accountName)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        // Вызов короткий, но без потолка флуд-сон MadelineProto держал бы сессию,
        // общую с telegram-support:sync. exit() в обработчике минует finally.
        $timeout = (int) config('services.telegram_support.sync_timeout_seconds', 120);
        $watchdog->arm($timeout, function (int $seconds) use ($reaper): void {
            $this->releaseMadelineSessionLock();
            $reaper->killDaemons();
            $reaper->clearIpcArtifacts();
            $this->error("Сухой прогон: timeout после {$seconds} с, демон сессии сброшен.");
        });

        try {
            $result = $this->withMadelineSessionLock(fn (): array => $audience->resolve(
                $clientFactory->open(),
                (string) $this->argument('folder'),
            ));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $watchdog->disarm();
        }

        if ($result === null) {
            $this->warn('Сессию держит другая команда. Повторите через минуту.');

            return self::FAILURE;
        }

        $this->report($result, $this->writeCsv($result));

        return self::SUCCESS;
    }

    /** Возвращает текст ошибки либо null, если сессия переключена. */
    private function useNamedSession(string $accountName): ?string
    {
        $account = TelegramSupportAccount::query()->where('name', $accountName)->first();

        if ($account === null) {
            return "Аккаунт «{$accountName}» не заведен в telegram_support_accounts.";
        }

        if (! $account->is_enabled) {
            return "Аккаунт «{$accountName}» отключен (is_enabled=0): вторая сессия той же личности нарушает D1.";
        }

        $path = (string) $account->session_path;
        if ($path === '') {
            return "У аккаунта «{$accountName}» не задан session_path.";
        }

        MadelineSessionContext::useSession(
            preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1 ? $path : base_path($path),
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeCsv(array $result): string
    {
        $directory = storage_path('app/telegram-folder-audience');
        File::ensureDirectoryExists($directory, 0700);

        $path = $directory.'/folder-'.$result['folder']['id'].'-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'wb');
        chmod($path, 0600);

        fputcsv($handle, ['user_id', 'name', 'username', 'status', 'is_contact', 'first_inbound_at'], ';', '"', '');
        foreach ($result['recipients'] as $row) {
            $row['is_contact'] = $row['is_contact'] ? 1 : 0;
            fputcsv($handle, array_values($row), ';', '"', '');
        }
        fclose($handle);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result, string $csvPath): void
    {
        $byStatus = array_count_values(array_column($result['recipients'], 'status'));

        $this->info('Папка: «'.$result['folder']['title'].'». Сухой прогон, ничего не отправлено.');
        $this->table(['status', 'count'], [
            ['eligible (писал сам)', $byStatus['eligible'] ?? 0],
            ['cold (входящие не подтверждены)', $byStatus['cold'] ?? 0],
            ['bot', $byStatus['bot'] ?? 0],
            ['deleted', $byStatus['deleted'] ?? 0],
            ['self', $byStatus['self'] ?? 0],
            ['группы/каналы (пропущены)', $result['skipped_group_peers']],
        ]);

        if ($result['dynamic_flags'] !== []) {
            $this->warn('Список НЕПОЛОН: в папке включены категории: '.implode(', ', $result['dynamic_flags']).'.');
        }

        $this->line("CSV: {$csvPath}");
    }
}
