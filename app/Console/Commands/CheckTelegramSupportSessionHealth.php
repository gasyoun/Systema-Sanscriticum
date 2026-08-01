<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\SupportObservability;
use App\Models\TelegramSupportAccount;
use App\Models\User;
use App\Services\Telegram\MadelineSessionHealer;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * W3.1 healthcheck (H595): раз в N минут проверяет каждую включённую
 * {@see TelegramSupportAccount} на протухший синк или ошибку.
 *
 * При `TELEGRAM_SUPPORT_AUTO_HEAL=true` и healable-ошибке / stale:
 * один раз за cooldown вызывает {@see RecoverTelegramSupportSession}
 * (kill IPC worker, clear sockets, unlock, resync) — чтобы не чинить
 * вручную каждый раз (прод 01.08.2026: worker 28 ч в disk sleep).
 */
class CheckTelegramSupportSessionHealth extends Command
{
    protected $signature = 'telegram-support:healthcheck {--dry : Только показать вердикт, без recover и уведомлений}';

    protected $description = 'Проверка здоровья Telegram-support сессий; опционально auto-heal IPC hang.';

    public function handle(MadelineSessionHealer $healer): int
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
        $healable = false;

        foreach ($accounts as $account) {
            $isStale = ! $account->last_successful_sync_at
                || $account->last_successful_sync_at->lt(now()->subMinutes($staleAfterMinutes));

            if ($isStale) {
                $lag = $account->last_successful_sync_at
                    ? $account->last_successful_sync_at->diffInMinutes(now()).' мин назад'
                    : 'никогда';
                $problems[] = "«{$account->name}»: синк протух (последний успешный: {$lag})";
                // Stale alone is enough to try heal (IPC hang often leaves a sticky last_sync_error).
                $healable = true;
            }

            if (! empty($account->last_sync_error)) {
                $problems[] = "«{$account->name}»: последняя ошибка синка — {$account->last_sync_error}";
                if ($healer->errorLooksHealable((string) $account->last_sync_error)) {
                    $healable = true;
                }
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
            $this->comment('--dry: recover и уведомления не выполнялись.');

            return self::SUCCESS;
        }

        // Auto-heal once per cooldown when enabled and problem looks like IPC hang / stale.
        if ($healable && $healer->autoHealEnabled() && ! $healer->isInCooldown()) {
            $this->warn('Auto-heal: запускаю telegram-support:recover…');
            $exit = Artisan::call('telegram-support:recover', ['--force' => true]);
            $this->line(trim(Artisan::output()));

            // Re-evaluate after heal.
            $accounts = TelegramSupportAccount::query()
                ->where('is_enabled', true)
                ->orderBy('name')
                ->get();
            $stillBad = false;
            foreach ($accounts as $account) {
                $isStale = ! $account->last_successful_sync_at
                    || $account->last_successful_sync_at->lt(now()->subMinutes($staleAfterMinutes));
                if ($isStale || ! empty($account->last_sync_error)) {
                    $stillBad = true;
                    break;
                }
            }

            if (! $stillBad && $exit === self::SUCCESS) {
                $this->info('Auto-heal: сессия восстановлена, алерт не шлём.');

                return self::SUCCESS;
            }

            $this->warn('Auto-heal: проблемы остались — шлём алерт.');
            // Refresh problem text for the alert body.
            $problems[] = 'Auto-heal recover выполнялся, но сессия всё ещё нездорова.';
        } elseif ($healable && $healer->autoHealEnabled() && $healer->isInCooldown()) {
            $this->comment('Auto-heal: в cooldown ('.$healer->cooldownMinutes().' мин) — только алерт.');
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
