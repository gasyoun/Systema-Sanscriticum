<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\SupportObservability;
use App\Models\TelegramSupportAccount;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * W3.1 healthcheck (H595): раз в N минут проверяет каждую включённую
 * {@see TelegramSupportAccount} на протухший синк (не было успешного прохода
 * дольше `services.telegram_support.stale_after_minutes`, тот же порог, что
 * {@see SupportObservability} использует для дашборда) или последнюю ошибку
 * синка, и алертит админов через Filament DB-уведомление — тот же паттерн
 * дежурного алерта, что {@see CheckReceivablesThreshold}.
 *
 * Не трогает сами сессии/синк — read-only проверка поверх уже записываемых
 * `last_successful_sync_at`/`last_sync_error` (см. TelegramSupportSyncService).
 */
class CheckTelegramSupportSessionHealth extends Command
{
    protected $signature = 'telegram-support:healthcheck {--dry : Только показать вердикт, без отправки уведомлений}';

    protected $description = 'Проверка здоровья Telegram-support сессий (лаг синка / ошибка) — алерт админам при проблеме.';

    public function handle(): int
    {
        $staleAfterMinutes = (int) config('services.telegram_support.stale_after_minutes', 15);

        $accounts = TelegramSupportAccount::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('Нет включённых Telegram-support аккаунтов — проверять нечего.');

            return self::SUCCESS;
        }

        $problems = [];

        foreach ($accounts as $account) {
            $isStale = ! $account->last_successful_sync_at
                || $account->last_successful_sync_at->lt(now()->subMinutes($staleAfterMinutes));

            if ($isStale) {
                $lag = $account->last_successful_sync_at
                    ? $account->last_successful_sync_at->diffInMinutes(now()).' мин назад'
                    : 'никогда';
                $problems[] = "«{$account->name}»: синк протух (последний успешный: {$lag})";
            }

            if (! empty($account->last_sync_error)) {
                $problems[] = "«{$account->name}»: последняя ошибка синка — {$account->last_sync_error}";
            }
        }

        if ($problems === []) {
            $this->info('Все Telegram-support сессии здоровы.');

            return self::SUCCESS;
        }

        $this->warn('Обнаружены проблемы:');
        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью админа — уведомление не отправлено.');

            return self::FAILURE;
        }

        $body = implode(' ', $problems);
        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Telegram-support: проблема с сессией')
                ->danger()
                ->body($body)
                ->actions([
                    Action::make('open')
                        ->label('Открыть наблюдаемость')
                        ->url(SupportObservability::getUrl(), shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());

        return self::SUCCESS;
    }
}
